<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro Kontrolní hlášení (DPHKH1) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * **VŽDY měsíční** — i pro kvartální plátce DPH. (User feedback: "kontrolní
 * hlášení se dělá měsíčně, ale DPH jen kvartálně pro některé plátce")
 *
 * Sekce KH:
 *   - **A.1** Plnění v režimu přenesené daňové povinnosti (dodavatel)
 *   - **A.2** Pořízení zboží z jiného členského státu (intra-EU acquisition)
 *   - **A.3** Plnění uskutečněná § 92a/b (dodání investičního zlata)
 *   - **A.4** Tuzemská plnění s DPH nad 10 000 Kč (vystavené)
 *   - **A.5** Tuzemská plnění s DPH **do** 10 000 Kč (sumace)
 *   - **B.1** Plnění v režimu přenesené daňové povinnosti (odběratel)
 *   - **B.2** Tuzemská přijatá plnění s DPH nad 10 000 Kč
 *   - **B.3** Tuzemská přijatá plnění s DPH **do** 10 000 Kč (sumace)
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní.
 */
final class KontrolniHlaseniBuilder
{
    /** Limit pro A.4 vs A.5 (a B.2 vs B.3) — nad 10 000 Kč jdou jednotlivě, do sumace */
    private const ITEM_VS_BULK_THRESHOLD = 10000.0;

    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
    ) {}

    /**
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $warnings = $this->validateSupplier($supplier);

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        // Všechny sekce z jedné projekce kanonických řádků (VatLedgerService).
        ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3]
            = $this->collectSections($supplierId, $start, $end);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        $dphkh = $dom->createElement('DPHKH1');
        $dphkh->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphkh);

        // VetaD — identifikační údaje (KH je VŽDY měsíční, jen `mesic`)
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('dokument', 'KH1');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('mesic', (string) $month);
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        $vetaD->setAttribute('khdph_forma', 'B'); // B = řádné podání
        $dphkh->appendChild($vetaD);

        // VetaP — identifikace plátce (sdíleno s DPHDP3 přes EpoSupplierBlockBuilder)
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphkh->appendChild($vetaP);

        // VetaA1 — Přenesená daňová povinnost (dodavatel).
        // XSD vyžaduje: dic_odb, c_evid_dd, duzp (NE "dppd"), zakl_dane1, kod_pred_pl.
        // kod_pred_pl '5' = obecný tuzemský reverse charge (defaultní hodnota, MFČR
        // číselník Kód předmětů plnění; ideálně by mělo přicházet z vat_classification_code).
        $rowNum = 0;
        foreach ($a1 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue; // Pattern [0-9]{1,10} required
            $rowNum++;
            $v = $dom->createElement('VetaA1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('duzp', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base']));
            $v->setAttribute('kod_pred_pl', '5');
            $dphkh->appendChild($v);
        }

        // VetaA2 — pořízení zboží z jiného členského státu (intra-EU acquisition).
        // Per XSD: k_stat (země dodavatele), vatid_dod (DIČ bez prefixu země),
        // c_evid_dd (číslo dokladu dodavatele), dppd (datum povinnosti přiznat daň
        // — required), zakl_dane1/dan1 (21%), zakl_dane2/dan2 (12%).
        // Plnění je z definice samovyměřené (vendor fakturuje bez DPH, my si daň
        // přiznáme sami) — `dan1`/`dan2` = base × sazba/100, ne pii.total_vat
        // (které je 0 pro RC).
        $rowNum = 0;
        foreach ($a2 as $r) {
            $vatId = $this->cleanDic($r['counterparty_dic'] ?? '');
            // Některé doklady (např. od neplátce v EU) nemusí mít VAT ID dodavatele
            // → atribut zůstává prázdný, jinak XSD pole povoluje.
            $rowNum++;
            $v = $dom->createElement('VetaA2');
            $v->setAttribute('c_radku', (string) $rowNum);
            $kStat = (string) ($r['country_iso2'] ?? '');
            if ($kStat !== '') $v->setAttribute('k_stat', $kStat);
            if ($vatId !== '') $v->setAttribute('vatid_dod', $vatId);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1',       $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2',       $this->formatAmount($r['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaA4 — tuzemská plnění nad 10 000 Kč (vystavené)
        $rowNum = 0;
        foreach ($a4 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $rowNum++;
            $taxDate = $this->formatDate($r['tax_date']);
            $v = $dom->createElement('VetaA4');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['varsymbol']);
            $v->setAttribute('dppd', $taxDate);
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_rezim_pl', '0');
            $v->setAttribute('zdph_44', 'N'); // N = nejedná se o opravu nedobytné pohledávky
            $dphkh->appendChild($v);
        }

        // VetaA5 — tuzemská plnění do 10 000 Kč (sumace, 1 řádek)
        if ($a5['count'] > 0) {
            $v = $dom->createElement('VetaA5');
            $v->setAttribute('zakl_dane1', $this->formatAmount($a5['base21']));
            $v->setAttribute('dan1', $this->formatAmount($a5['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($a5['base12']));
            $v->setAttribute('dan2', $this->formatAmount($a5['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaB1 — Přenesená daňová povinnost (odběratel)
        $rowNum = 0;
        foreach ($b1 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $rowNum++;
            $v = $dom->createElement('VetaB1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_dod', $cleanDic);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base']));
            $v->setAttribute('kod_pred_pl', '5'); // tuzemský reverse charge
            $dphkh->appendChild($v);
        }

        // VetaB2 — přijatá tuzemská nad 10 000 Kč.
        // XSD vyžaduje: pomer (A/N — poměrný odpočet podle §75) a zdph_44
        // (N = běžné, P = oprava nedobytné pohledávky podle §74b, A = §44 do 31.3.2019).
        // Default: oba 'N' (běžný odpočet, žádná oprava).
        $rowNum = 0;
        foreach ($b2 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $rowNum++;
            $v = $dom->createElement('VetaB2');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_dod', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('pomer', 'N');
            $v->setAttribute('zdph_44', 'N');
            $dphkh->appendChild($v);
        }

        // VetaB3 — přijatá tuzemská do 10 000 Kč (sumace)
        if ($b3['count'] > 0) {
            $v = $dom->createElement('VetaB3');
            $v->setAttribute('zakl_dane1', $this->formatAmount($b3['base21']));
            $v->setAttribute('dan1', $this->formatAmount($b3['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($b3['base12']));
            $v->setAttribute('dan2', $this->formatAmount($b3['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaC — rekapitulace plnění za období (obrat = uskutečněná, pln = přijatá).
        // Sumace všech sekcí: A4+A5 (sales), B2+B3 (purchases), A1 (RC sales),
        // B1 (RC purchases), A2 (EU acquisitions → celk_zd_a2).
        $obrat23 = 0.0; $obrat5 = 0.0;
        foreach ($a4 as $r) { $obrat23 += (float) $r['base21']; $obrat5 += (float) $r['base12']; }
        $obrat23 += (float) ($a5['base21'] ?? 0); $obrat5 += (float) ($a5['base12'] ?? 0);
        $pln23 = 0.0; $pln5 = 0.0;
        foreach ($b2 as $r) { $pln23 += (float) $r['base21']; $pln5 += (float) $r['base12']; }
        $pln23 += (float) ($b3['base21'] ?? 0); $pln5 += (float) ($b3['base12'] ?? 0);
        $rezPren23 = 0.0; foreach ($a1 as $r) { $rezPren23 += (float) $r['base']; }
        $plnRezPren = 0.0; foreach ($b1 as $r) { $plnRezPren += (float) $r['base']; }
        $vetaC = $dom->createElement('VetaC');
        $vetaC->setAttribute('obrat23',      $this->formatAmount($obrat23));
        $vetaC->setAttribute('obrat5',       $this->formatAmount($obrat5));
        $vetaC->setAttribute('pln23',        $this->formatAmount($pln23));
        $vetaC->setAttribute('pln5',         $this->formatAmount($pln5));
        $vetaC->setAttribute('pln_rez_pren', $this->formatAmount($plnRezPren));
        $vetaC->setAttribute('rez_pren23',   $this->formatAmount($rezPren23));
        // rez_pren5 = 0 záměrně: tuzemský reverse charge (§ 92a–92e — stavební práce,
        // odpad, zlato, …) je v ČR vždy v základní sazbě 21 %, snížená 12% RC neexistuje.
        $vetaC->setAttribute('rez_pren5',    '0');
        // celk_zd_a2 = celkový základ pořízení zboží z JČS (sekce A.2)
        $celkA2 = 0.0;
        foreach ($a2 as $r) { $celkA2 += (float) $r['base21'] + (float) $r['base12']; }
        $vetaC->setAttribute('celk_zd_a2',   $this->formatAmount($celkA2));
        $dphkh->appendChild($vetaC);

        // Termín podání = 25. následujícího měsíce
        $deadlineMonth = $month + 1;
        $deadlineYear = $year;
        if ($deadlineMonth > 12) { $deadlineMonth -= 12; $deadlineYear++; }
        $deadline = sprintf('%04d-%02d-25', $deadlineYear, $deadlineMonth);

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => [
                'period'              => sprintf('%04d-%02d', $year, $month),
                'a1_count'            => count($a1),
                'a2_count'            => count($a2),
                'a4_count'            => count($a4),
                'a5_count_aggregated' => $a5['count'],
                'b1_count'            => count($b1),
                'b2_count'            => count($b2),
                'b3_count_aggregated' => $b3['count'],
                'submission_deadline' => $deadline,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Projekce kanonických řádků (VatLedgerService) na sekce KH. Nahrazuje 5 původních
     * SQL kolektorů + loadInvoiceVatBreakdown. Per faktura agregujeme základ/daň po
     * sazbách + příznaky sekce, pak směrujeme:
     *   - A.1 = vystavený reverse charge
     *   - A.2 = pořízení zboží z JČS (kh_section A.2; samovyměřená daň ze služby)
     *   - A.4/A.5 = vystavená tuzemská zdanitelná (nad/do limitu + DIČ)
     *   - B.1 = přijatý tuzemský RC (ne A.2)
     *   - B.2/B.3 = přijatá tuzemská zdanitelná
     * Práh `abs()`, plnění bez DIČ → sumace, bez zdanitelného základu → vyloučeno.
     *
     * @return array{a1:list<array<string,mixed>>, a2:list<array<string,mixed>>,
     *   a4:list<array<string,mixed>>, a5:array<string,mixed>, b1:list<array<string,mixed>>,
     *   b2:list<array<string,mixed>>, b3:array<string,mixed>}
     */
    private function collectSections(int $supplierId, string $start, string $end): array
    {
        // Agregace kanonických řádků per (zdroj, faktura).
        $inv = [];
        foreach ($this->ledger->rows($supplierId, $start, $end, includeDrafts: false) as $r) {
            $key = $r['source'] . ':' . $r['invoice_id'];
            if (!isset($inv[$key])) {
                $inv[$key] = [
                    'source'                => $r['source'],
                    'varsymbol'             => $r['doc_number'],
                    'vendor_invoice_number' => $r['vendor_invoice_number'],
                    'tax_date'              => $r['tax_date'],
                    'dic'                   => $this->cleanDic($r['counterparty_dic']),
                    'country_iso2'          => $r['country_iso2'],
                    'total_czk'             => (float) $r['total_with_vat_czk'],
                    'is_rc' => false, 'has_a2' => false, 'has_b1' => false,
                    'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0, 'base_total' => 0.0,
                    'a2_base21' => 0.0, 'a2_vat21' => 0.0, 'a2_base12' => 0.0, 'a2_vat12' => 0.0,
                ];
            }
            $g = &$inv[$key];
            if ($r['is_reverse_charge']) $g['is_rc'] = true;
            if ($r['kh_section'] === 'A.2') $g['has_a2'] = true;
            if ($r['kh_section'] === 'B.1') $g['has_b1'] = true;
            $base = (float) $r['base_czk'];
            $vat  = (float) $r['vat_czk'];
            $g['base_total'] += $base;
            if ($r['vat_rate'] >= 20.5) { $g['base21'] += $base; $g['vat21'] += $vat; }
            elseif ($r['vat_rate'] > 0) { $g['base12'] += $base; $g['vat12'] += $vat; }
            if ($r['kh_section'] === 'A.2') {
                if ($r['vat_rate'] >= 20.5) { $g['a2_base21'] += $base; $g['a2_vat21'] += $vat; }
                elseif ($r['vat_rate'] > 0) { $g['a2_base12'] += $base; $g['a2_vat12'] += $vat; }
            }
            unset($g);
        }

        $a1 = []; $a2 = []; $a4 = []; $b1 = []; $b2 = [];
        $a5 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        $b3 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        $zeroBase = fn (array $g) => abs($g['base21']) < 0.005 && abs($g['base12']) < 0.005;

        foreach ($inv as $g) {
            $hasDic = $g['dic'] !== '';
            $overLimit = abs($g['total_czk']) >= self::ITEM_VS_BULK_THRESHOLD;

            if ($g['source'] === 'sale') {
                if ($g['is_rc']) {
                    $a1[] = ['counterparty_dic' => $g['dic'], 'vendor_invoice_number' => $g['varsymbol'],
                             'tax_date' => $g['tax_date'], 'base' => $g['base_total']];
                    continue;
                }
                if ($zeroBase($g)) continue; // osvobozené / EU dodání / vývoz → ne A.4/A.5
                $row = ['varsymbol' => $g['varsymbol'], 'tax_date' => $g['tax_date'], 'counterparty_dic' => $g['dic'],
                        'base21' => $g['base21'], 'vat21' => $g['vat21'], 'base12' => $g['base12'], 'vat12' => $g['vat12']];
                if ($overLimit && $hasDic) {
                    $a4[] = $row;
                } else {
                    $a5['count']++; $a5['base21'] += $g['base21']; $a5['vat21'] += $g['vat21'];
                    $a5['base12'] += $g['base12']; $a5['vat12'] += $g['vat12'];
                }
            } else { // purchase
                if ($g['has_a2']) {
                    $a2[] = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                             'counterparty_dic' => $g['dic'], 'country_iso2' => $g['country_iso2'],
                             'base21' => $g['a2_base21'], 'vat21' => $g['a2_vat21'],
                             'base12' => $g['a2_base12'], 'vat12' => $g['a2_vat12']];
                    continue;
                }
                if ($g['is_rc']) { // tuzemský RC příjemce (A.2 už odchyceno výše)
                    $b1[] = ['counterparty_dic' => $g['dic'], 'vendor_invoice_number' => $g['vendor_invoice_number'],
                             'tax_date' => $g['tax_date'], 'base' => $g['base_total']];
                    continue;
                }
                if ($g['has_b1']) continue;       // B.1 sekce bez RC flagu → nepatří do B.2
                if ($zeroBase($g)) continue;      // osvobozená přijatá bez nároku → ne B.2/B.3
                $row = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                        'counterparty_dic' => $g['dic'], 'base21' => $g['base21'], 'vat21' => $g['vat21'],
                        'base12' => $g['base12'], 'vat12' => $g['vat12']];
                if ($overLimit && $hasDic) {
                    $b2[] = $row;
                } else {
                    $b3['count']++; $b3['base21'] += $g['base21']; $b3['vat21'] += $g['vat21'];
                    $b3['base12'] += $g['base12']; $b3['vat12'] += $g['vat12'];
                }
            }
        }

        return ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3];
    }

    /** @return list<string> warnings */
    private function validateSupplier(array $s): array
    {
        $w = [];
        if (!$s['is_vat_payer']) $w[] = 'Tenant není plátce DPH — KH nemusí být relevantní.';
        if (empty($s['financial_office_code'])) $w[] = 'Chybí kód finančního úřadu.';
        if (empty($s['dic'])) $w[] = 'Chybí DIČ.';
        return $w;
    }

    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.data_box_type, s.data_box_id,
                    s.email, s.phone, s.cz_nace_code,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_telefon, s.sest_email, s.sest_funkce
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

    /** DIČ pro KH XML — odstraní CZ prefix, jen číslice. */
    private function cleanDic(?string $dic): string
    {
        if (!$dic) return '';
        // CZ12345678 → 12345678. Pattern v XSD je [0-9]{1,10}, takže strip vše ne-digit po prefixu.
        $clean = preg_replace('/^CZ/i', '', strtoupper(trim($dic))) ?? '';
        return preg_replace('/[^0-9]/', '', $clean) ?? '';
    }

    /** Date pro KH XML — convert YYYY-MM-DD na DD.MM.YYYY (EPO datum format). */
    private function formatDate(?string $isoDate): string
    {
        if (!$isoDate) return '';
        try {
            return (new \DateTimeImmutable($isoDate))->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
