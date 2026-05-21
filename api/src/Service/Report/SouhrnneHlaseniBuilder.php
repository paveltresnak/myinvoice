<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro Souhrnné hlášení (DPHSHV1) — EPO portál MFČR.
 *
 * Verze EPO: 06.01 (platná 2025+).
 *
 * **K čemu slouží:**
 * Výkaz EU dodání zboží/služeb (intra-community supplies) v režimu B2B —
 * dodávky plátcům v jiných členských státech EU. Submit per měsíc (povinnost
 * pro plátce DPH s alespoň jednou EU dodávkou v daném měsíci).
 *
 * **Sekce SH:**
 * Per řádek (group by counterparty VAT_ID + kód plnění):
 *   - Kód plnění:
 *     - **0** = Dodání zboží do jiného členského státu (řádek 20 DPHDP3, VAT kód "20")
 *     - **1** = Trojstranný obchod (zprostředkovatel)
 *     - **2** = Poskytnutí služby s místem plnění v jiném státě (VAT kód "22")
 *     - **3** = Přemístění zboží
 *   - DIČ kupujícího (s prefixem země, např. SK1234567890)
 *   - Hodnota plnění v CZK (základ daně, bez DPH)
 *   - Počet plnění
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním ověřit s účetní.
 */
final class SouhrnneHlaseniBuilder
{
    /**
     * Mapování VAT klasifikačních kódů na SH typ plnění.
     *   "20" (EU dodání zboží) → 0
     *   "22" (EU služby)        → 2
     *   "21" (EU dodání po trojstranném obchodu — pokud máte custom kód) → 1
     */
    private const VAT_CODE_TO_SH_TYPE = [
        '20' => '0',  // dodání zboží
        '21' => '1',  // trojstranný obchod (pokud existuje custom kód)
        '22' => '2',  // poskytnutí služby
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $warnings = $this->validateSupplier($supplier);

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        $rows = $this->collectEuSupplies($supplierId, $start, $end);

        if (empty($rows)) {
            $warnings[] = 'V tomto měsíci nejsou žádné EU dodávky — SH se nepodává.';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        $shv = $dom->createElement('DPHSHV');
        $shv->setAttribute('verzePis', '06.01');
        $pisemnost->appendChild($shv);

        // VetaD — typ podání + perioda (typ_platce/typ_ds jdou v VetaP per XSD).
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('mesic', (string) $month);
        $vetaD->setAttribute('shvies_forma', 'B'); // B = řádné
        $vetaD->setAttribute('dokument', 'SHV');
        $shv->appendChild($vetaD);

        // VetaP — identifikace poplatníka
        $vetaP = $dom->createElement('VetaP');
        $vetaP->setAttribute('c_ufo', (string) ($supplier['financial_office_code'] ?: '451'));
        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        $dic = (string) ($supplier['dic'] ?? '');
        $cleanDic = preg_replace('/^CZ/i', '', $dic) ?? $dic;
        $cleanDic = preg_replace('/[^0-9]/', '', $cleanDic) ?? '';
        $vetaP->setAttribute('dic', $cleanDic);
        $vetaP->setAttribute('typ_ds', $supplier['data_box_type'] ?: 'F');
        if ($supplier['taxpayer_type'] === 'po') {
            $vetaP->setAttribute('zkrobchjm', (string) $supplier['company_name']);
        } else {
            $parts = explode(' ', trim((string) $supplier['company_name']), 2);
            $vetaP->setAttribute('jmeno', $parts[0] ?? '');
            $vetaP->setAttribute('prijmeni', $parts[1] ?? $parts[0] ?? '');
        }
        $vetaP->setAttribute('ulice', (string) ($supplier['street'] ?? ''));
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute('psc', preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?? '');
        $vetaP->setAttribute('stat', (string) ($supplier['country_iso2'] ?? 'CZ'));
        $shv->appendChild($vetaP);

        // VetaA1 — jednotlivé řádky (per VAT_ID + typ plnění)
        $totalRows = 0;
        $totalAmount = 0.0;
        foreach ($rows as $r) {
            $v = $dom->createElement('VetaA1');
            $v->setAttribute('k_stat', $r['country_iso2']);
            $v->setAttribute('vatid_pod', $r['vat_id']);
            $v->setAttribute('kod_plneni', $r['sh_type']);
            $v->setAttribute('pln_hodnota', $this->formatAmount($r['amount']));
            $v->setAttribute('pln_pocet', (string) $r['count']);
            $shv->appendChild($v);
            $totalRows++;
            $totalAmount += $r['amount'];
        }

        // Termín podání: 25. dne následujícího měsíce
        $deadlineMonth = $month + 1;
        $deadlineYear = $year;
        if ($deadlineMonth > 12) { $deadlineMonth -= 12; $deadlineYear++; }
        $deadline = sprintf('%04d-%02d-25', $deadlineYear, $deadlineMonth);

        return [
            'xml'     => $dom->saveXML() ?: '',
            'summary' => [
                'period'              => sprintf('%04d-%02d', $year, $month),
                'rows_count'          => $totalRows,
                'total_amount'        => round($totalAmount, 2),
                'rows'                => $rows,
                'submission_deadline' => $deadline,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Sebere EU dodávky (vystavené faktury s VAT kódem 20/22 + EU klient s DIČ).
     * Agreguje per (country_iso2, vat_id, sh_type).
     *
     * @return list<array{country_iso2:string, vat_id:string, sh_type:string,
     *                   amount:float, count:int, counterparty_name:string}>
     */
    private function collectEuSupplies(int $supplierId, string $start, string $end): array
    {
        // VAT klasifikační kódy pro EU (sale): 20, 21, 22. Plně parameterized
        // přes IN(?,?,?) placeholders — žádné string concatenation, defensive
        // i pro budoucí rozšíření o custom kódy.
        $codeKeys = array_map('strval', array_keys(self::VAT_CODE_TO_SH_TYPE));
        $placeholders = implode(',', array_fill(0, count($codeKeys), '?'));

        $sql = "
            SELECT cnt.iso2 AS country_iso2,
                   c.dic AS dic_raw,
                   c.company_name AS counterparty_name,
                   COALESCE(ii.vat_classification_code, i.vat_classification_code) AS vat_code,
                   SUM(COALESCE(ii.total_without_vat, 0)) AS amount,
                   COUNT(DISTINCT i.id) AS inv_count
              FROM invoices i
              JOIN clients c    ON c.id = i.client_id
              JOIN countries cnt ON cnt.id = c.country_id
              JOIN invoice_items ii ON ii.invoice_id = i.id
             WHERE i.supplier_id = ?
               AND i.status NOT IN ('draft', 'cancelled')
               AND i.invoice_type != 'proforma'
               AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
               AND cnt.is_eu = 1
               AND cnt.iso2 != 'CZ'
               AND c.dic IS NOT NULL AND c.dic != ''
               AND COALESCE(ii.vat_classification_code, i.vat_classification_code) IN ({$placeholders})
          GROUP BY cnt.iso2, c.dic, c.company_name, vat_code
          ORDER BY cnt.iso2, c.dic
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $start, $end, ...$codeKeys]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Konsolidace: pokud má klient 2 řádky se stejným vat_code (shouldn't happen po GROUP BY,
        // ale po normalizaci DIČ se může stát např. SK1234 vs 1234), GROUP BY post-process.
        $result = [];
        foreach ($rows as $r) {
            $vatId = $this->normalizeVatId((string) $r['dic_raw'], (string) $r['country_iso2']);
            if ($vatId === '') continue; // bez DIČ nelze podat SH
            $shType = self::VAT_CODE_TO_SH_TYPE[$r['vat_code']] ?? '0';
            $key = "{$r['country_iso2']}|{$vatId}|{$shType}";
            if (!isset($result[$key])) {
                $result[$key] = [
                    'country_iso2'      => $r['country_iso2'],
                    'vat_id'            => $vatId,
                    'sh_type'           => $shType,
                    'amount'            => 0.0,
                    'count'             => 0,
                    'counterparty_name' => (string) $r['counterparty_name'],
                ];
            }
            $result[$key]['amount'] += (float) $r['amount'];
            $result[$key]['count']  += (int) $r['inv_count'];
        }
        return array_values($result);
    }

    /**
     * Normalize VAT ID — odstraní mezery + uppercase. Pokud nemá country prefix,
     * přidá z `country_iso2`.
     */
    private function normalizeVatId(string $dic, string $countryIso2): string
    {
        $dic = preg_replace('/\s+/', '', strtoupper(trim($dic))) ?? '';
        if ($dic === '') return '';
        // Pokud začíná country code (2 písmena), je OK. Jinak prepend.
        if (preg_match('/^[A-Z]{2}/', $dic)) return $dic;
        return $countryIso2 . $dic;
    }

    /**
     * Note: Souhrnné hlášení **nevyžaduje** být plátcem DPH.
     * Podávají ho i **identifikované osoby** (neplátci, kteří poskytují služby EU plátcům
     * nebo nakupují zboží z EU nad limit). DIČ je u identifikované osoby ve formátu
     * CZ + RČ/IČO, prefix CZ se v SH XML ponechává.
     *
     * @return list<string>
     */
    private function validateSupplier(array $s): array
    {
        $w = [];
        if (empty($s['financial_office_code'])) $w[] = 'Chybí kód finančního úřadu.';
        if (empty($s['dic'])) $w[] = 'Chybí DIČ (povinné i pro identifikovanou osobu).';
        return $w;
    }

    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer,
                    s.taxpayer_type, s.financial_office_code,
                    s.workplace_code, s.data_box_type, s.data_box_id
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        return $row;
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        return is_file($verFile) ? trim((string) file_get_contents($verFile)) : null;
    }

    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }
}
