<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use Smalot\PdfParser\Parser as PdfTextParser;

/**
 * Vytahá z PDF "K úhradě" hodnotu — částku, kterou skutečně faktura žádá k zaplacení.
 *
 * Hierarchie zdrojů (od nejlevnější a nejdeterminističtější):
 *   1. **Embedded ISDOC** (PDF/A-3 příloha s ISDOC XML — iDoklad, Fakturoid,
 *      Pohoda, MyInvoice). `LegalMonetaryTotal/PayableRoundedAmount` je
 *      autoritativní `K úhradě`. ~10 ms, žádné API volání.
 *   2. **PDF text regex** — extract text přes smalot/pdfparser, najít frázi
 *      `K úhradě` / `Celkem k platbě` / `Celkem k úhradě` / `TOTAL DUE`
 *      a nejbližší peněžní hodnotu. Funguje na 80-90 % strukturovaných
 *      českých faktur. ~50-200 ms, žádné API volání.
 *   3. **AI fallback** (callback) — pro fragile / skenované / non-standard
 *      PDFs kde 1 i 2 selhalo. ~3-8 sekund + cena tokenů.
 *
 * Použití (např. v `recheck-ai-extracted-invoices.php`):
 *
 *     $result = $extractor->extract($pdfBytes, function () use ($pdfBytes) {
 *         // fallback callback — drahý AI volání, spustí se jen pokud isdoc + regex selžou
 *         $ai = $anthropic->extractInvoice($supId, $pdfBytes);
 *         return $ai['ok'] ? (float) ($ai['data']['total_with_vat'] ?? 0) : null;
 *     });
 *     // $result['total'] - float nebo null
 *     // $result['source'] - 'isdoc' | 'pdf-text' | 'ai' | null
 */
final class PdfTotalExtractor
{
    /**
     * Klíčové fráze pro "K úhradě" v různých formulacích.
     * Hledáme case-insensitively, povolíme volitelné `:` a libovolný whitespace
     * mezi frází a číslem.
     */
    private const TOTAL_PHRASES = [
        'Celkem k úhradě',
        'Celkem k platbě',
        'Celkem k platbe',         // bez diakritiky
        'K úhradě',
        'K uhrade',
        'K platbě',
        'Celková částka k úhradě',
        'Cena celkem',
        'Celková cena',
        'Celkem k zaplacení',
        'CELKEM',
        'Total to pay',
        'Total amount due',
        'Amount due',
        'TOTAL DUE',
        'Total',
    ];

    public function __construct(
        private readonly PdfIsdocExtractor $isdocExtractor,
    ) {}

    /**
     * Pokusí se extrahovat PDF "K úhradě" hierarchií zdrojů.
     *
     * @param string $pdfBytes raw PDF
     * @param callable|null $aiFallback Optional callback(): ?float — AI extractor
     *   pro fallback. Volá se jen pokud isdoc i regex selžou. Vrací total nebo null.
     * @return array{total: ?float, source: ?string, debug: array<string,mixed>}
     */
    public function extract(string $pdfBytes, ?callable $aiFallback = null): array
    {
        $debug = [];

        // 1) ISDOC stage
        $isdocTotal = $this->tryIsdoc($pdfBytes, $debug);
        if ($isdocTotal !== null) {
            return ['total' => $isdocTotal, 'source' => 'isdoc', 'debug' => $debug];
        }

        // 2) PDF text regex stage
        $regexTotal = $this->tryTextRegex($pdfBytes, $debug);
        if ($regexTotal !== null) {
            return ['total' => $regexTotal, 'source' => 'pdf-text', 'debug' => $debug];
        }

        // 3) AI fallback
        if ($aiFallback !== null) {
            $aiTotal = $aiFallback();
            if ($aiTotal !== null && $aiTotal > 0) {
                $debug['ai_total'] = $aiTotal;
                return ['total' => (float) $aiTotal, 'source' => 'ai', 'debug' => $debug];
            }
            $debug['ai_returned'] = $aiTotal;
        }

        return ['total' => null, 'source' => null, 'debug' => $debug];
    }

    /**
     * Načíst total z embedded ISDOC XML (LegalMonetaryTotal/PayableRoundedAmount).
     * Vrací null pokud PDF nemá ISDOC nebo XML nemá tento element.
     */
    private function tryIsdoc(string $pdfBytes, array &$debug): ?float
    {
        $xml = $this->isdocExtractor->extract($pdfBytes);
        if ($xml === null) {
            $debug['isdoc'] = 'none';
            return null;
        }
        $debug['isdoc'] = 'found';

        // Parse XML, hledáme LegalMonetaryTotal/PayableRoundedAmount jako autoritativní
        // "K úhradě". Fallback na TaxInclusiveAmount (suma vč. DPH, před zaokrouhlením).
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);
            $ns = (string) $dom->documentElement?->lookupNamespaceUri(null);
            if ($ns !== '') {
                $xpath->registerNamespace('i', $ns);
            }
            foreach (['i:LegalMonetaryTotal/i:PayableRoundedAmount', 'i:LegalMonetaryTotal/i:TaxInclusiveAmount'] as $path) {
                $node = $xpath->query('//' . $path)->item(0);
                if ($node !== null && $node->textContent !== '') {
                    $val = (float) str_replace([',', ' '], ['.', ''], $node->textContent);
                    if ($val > 0) {
                        $debug['isdoc_field'] = $path;
                        return $val;
                    }
                }
            }
        } catch (\Throwable $e) {
            $debug['isdoc_parse_error'] = $e->getMessage();
        }
        return null;
    }

    /**
     * Extrahovat text PDF a najít největší peněžní hodnotu s **mandatorním
     * currency suffixem** (Kč / CZK / EUR / USD / €).
     *
     * Heuristika: total faktury je typicky největší peněžní hodnota
     * s měnovým suffixem v dokumentu. Filtrujeme:
     *   - data (`16.04.2026` nemá Kč suffix → skip)
     *   - VS / IČ / telefoní čísla (nemají Kč suffix → skip)
     *   - dílčí položky (mají suffix, ale menší než total)
     *
     * Funguje robustně napříč různými PDF layouty (table-based i flow-based)
     * protože nezávisí na pozici fráze a hodnoty — jen na tom, že total je
     * největší z čísel se suffixem.
     *
     * Limit: dvě peněžní hodnoty velmi blízko sobě (např. "Celkem k platbě
     * za zúčtované období 1 502,03" vs "Celkem k platbě 1 502,00" —
     * Vodafone-style multi-period souhrn) → vrátí MAX = 1 502,03, což může
     * být o pár haléřů vedle skutečné "K úhradě" (1 502,00). Pro recheck
     * purpose (2 % threshold) je to v pohodě — flag se neaktivuje při
     * rozdílu pod 0,1 %. Pokud uživatel chce ±0,01 Kč přesnost, použije
     * AI fallback.
     */
    private function tryTextRegex(string $pdfBytes, array &$debug): ?float
    {
        try {
            $parser = new PdfTextParser();
            $doc = $parser->parseContent($pdfBytes);
            $text = $doc->getText();
        } catch (\Throwable $e) {
            $debug['text_parse_error'] = $e->getMessage();
            return null;
        }

        if ($text === '') {
            $debug['text'] = 'empty';
            return null;
        }

        // Money + mandatory currency suffix. Symboly Kč/€ a kódy CZK/EUR/USD.
        // Decimal pattern: 1 234,56 / 1.234,56 / 12345,67 / 1234.56 / -123,45
        $pattern = '/(-?\d{1,3}(?:[\s\xc2\xa0.]\d{3})*[,.]\d{2}|-?\d+[,.]\d{2})\s*(K\xc4\x8d|Kč|CZK|EUR|USD|€|\$)/u';

        if (!preg_match_all($pattern, $text, $m, PREG_SET_ORDER)) {
            $debug['text'] = 'no_money_with_currency';
            return null;
        }

        $best = 0.0;
        $bestRaw = '';
        foreach ($m as $match) {
            // abs() — záporné hodnoty (slevy, vyrovnání) ignorujeme při hledání MAX
            $val = abs($this->parseMoney($match[1]));
            if ($val > $best) {
                $best = $val;
                $bestRaw = $match[1] . ' ' . $match[2];
            }
        }

        if ($best <= 0) {
            $debug['text'] = 'zero_max';
            return null;
        }

        $debug['text_match_count'] = count($m);
        $debug['text_raw_number'] = $bestRaw;
        return $best;
    }

    /**
     * Parse českou peněžní notaci na float.
     * Příklady: "1 502,00" → 1502.00, "1.502,00" → 1502.00, "1502.00" → 1502.00.
     *
     * Heuristika: poslední `,` nebo `.` před 1-2 digity je desetinný separátor.
     * Vše ostatní (mezery, jiné `.`/`,`) jsou separátory tisíců → smazat.
     */
    private function parseMoney(string $raw): float
    {
        $raw = trim(str_replace(["\xc2\xa0"], ' ', $raw));
        // Najdi pozici desetinného separátoru (poslední , nebo . následované 1-2 digity před koncem)
        if (preg_match('/[,.](\d{1,2})$/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $decPos = $m[0][1];
            $intPart = preg_replace('/[^\d]/', '', substr($raw, 0, $decPos)) ?? '';
            $decPart = $m[1][0];
            return (float) ($intPart . '.' . $decPart);
        }
        // Bez desetinného separátoru — celé číslo
        $clean = preg_replace('/[^\d]/', '', $raw) ?? '';
        return $clean === '' ? 0.0 : (float) $clean;
    }
}
