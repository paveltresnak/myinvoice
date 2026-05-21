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
        private readonly VatClassificationMapper $mapper,
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

        // Sekce A.4 / A.5 (vystavené tuzemsko nad / do prahu)
        [$a4, $a5] = $this->collectIssuedRows($supplierId, $start, $end);
        // Sekce B.2 / B.3 (přijaté tuzemsko)
        [$b2, $b3] = $this->collectReceivedRows($supplierId, $start, $end);
        // Sekce A.1 / B.1 (reverse charge — výsledně směřujeme na kódy s is_reverse_charge=1)
        $a1 = $this->collectReverseChargeIssued($supplierId, $start, $end);
        $b1 = $this->collectReverseChargePurchases($supplierId, $start, $end);

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

        // VetaD — identifikační údaje (zde KH JE měsíční, takže jen `mesic`)
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('mesic', (string) $month);
        $vetaD->setAttribute('khdph_forma', 'B'); // B = řádné podání
        $vetaD->setAttribute('dokument', 'KH1');
        $dphkh->appendChild($vetaD);

        // VetaP — identifikace plátce (typ_ds + c_ufo zde, ne v VetaD per XSD).
        $vetaP = $dom->createElement('VetaP');
        // c_ufo (kód FÚ) je required. Fallback "451" (Praha 1) pokud chybí, s warningem.
        $vetaP->setAttribute('c_ufo', (string) ($supplier['financial_office_code'] ?: '451'));
        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        $dic = (string) ($supplier['dic'] ?? '');
        $vetaP->setAttribute('dic', preg_replace('/^CZ/i', '', $dic) ?? $dic);
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
        $dphkh->appendChild($vetaP);

        // VetaA1 — Přenesená daňová povinnost (dodavatel)
        foreach ($a1 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue; // Pattern [0-9]{1,10} required
            $v = $dom->createElement('VetaA1');
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base']));
            $dphkh->appendChild($v);
        }

        // VetaA4 — tuzemská plnění nad 10 000 Kč (vystavené)
        foreach ($a4 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $taxDate = $this->formatDate($r['tax_date']);
            $v = $dom->createElement('VetaA4');
            $v->setAttribute('c_evid_dd', (string) $r['varsymbol']);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('dppd', $taxDate);
            $v->setAttribute('zdph_44', 'N'); // N = nejedná se o opravu nedobytné pohledávky (default flow)
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_rezim_pl', '0');
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
        foreach ($b1 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $v = $dom->createElement('VetaB1');
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_dod', $cleanDic);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base']));
            $v->setAttribute('kod_pred_pl', '5'); // tuzemský reverse charge
            $dphkh->appendChild($v);
        }

        // VetaB2 — přijatá tuzemská nad 10 000 Kč
        foreach ($b2 as $r) {
            $cleanDic = $this->cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $v = $dom->createElement('VetaB2');
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_dod', $cleanDic);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
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
     * Vystavené faktury rozdělené do A.4 (nad limit, jednotlivě) a A.5 (do limitu, sumace).
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
     */
    private function collectIssuedRows(int $supplierId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare("
            SELECT i.id, i.varsymbol, COALESCE(i.tax_date, i.issue_date) AS tax_date,
                   i.total_without_vat, i.total_vat, i.total_with_vat,
                   c.dic AS counterparty_dic, c.company_name AS counterparty_name
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
             WHERE i.supplier_id = ?
               AND i.status NOT IN ('draft', 'cancelled')
               AND i.invoice_type != 'proforma'
               AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$supplierId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $a4 = [];
        $a5 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        foreach ($rows as $r) {
            // Per-invoice VAT breakdown — load items aggregated by rate
            $breakdown = $this->loadInvoiceVatBreakdown((int) $r['id'], 'invoice');
            $row = [
                'varsymbol'         => $r['varsymbol'],
                'tax_date'          => $r['tax_date'],
                'counterparty_dic'  => $this->cleanDic($r['counterparty_dic']),
                'base21'            => $breakdown['base21'],
                'vat21'             => $breakdown['vat21'],
                'base12'            => $breakdown['base12'],
                'vat12'             => $breakdown['vat12'],
            ];
            if ((float) $r['total_with_vat'] >= self::ITEM_VS_BULK_THRESHOLD) {
                $a4[] = $row;
            } else {
                $a5['count']++;
                $a5['base21'] += $row['base21'];
                $a5['vat21']  += $row['vat21'];
                $a5['base12'] += $row['base12'];
                $a5['vat12']  += $row['vat12'];
            }
        }
        return [$a4, $a5];
    }

    /**
     * Přijaté faktury rozdělené do B.2 (nad limit, jednotlivě) a B.3 (sumace).
     */
    private function collectReceivedRows(int $supplierId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare("
            SELECT pi.id, pi.vendor_invoice_number, COALESCE(pi.tax_date, pi.issue_date) AS tax_date,
                   pi.total_with_vat,
                   c.dic AS counterparty_dic, c.company_name AS counterparty_name
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
             WHERE pi.supplier_id = ?
               AND pi.status NOT IN ('draft', 'cancelled')
               AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$supplierId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $b2 = [];
        $b3 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        foreach ($rows as $r) {
            $breakdown = $this->loadInvoiceVatBreakdown((int) $r['id'], 'purchase_invoice');
            $row = [
                'vendor_invoice_number' => $r['vendor_invoice_number'],
                'tax_date'              => $r['tax_date'],
                'counterparty_dic'      => $this->cleanDic($r['counterparty_dic']),
                'base21'                => $breakdown['base21'],
                'vat21'                 => $breakdown['vat21'],
                'base12'                => $breakdown['base12'],
                'vat12'                 => $breakdown['vat12'],
            ];
            if ((float) $r['total_with_vat'] >= self::ITEM_VS_BULK_THRESHOLD) {
                $b2[] = $row;
            } else {
                $b3['count']++;
                $b3['base21'] += $row['base21'];
                $b3['vat21']  += $row['vat21'];
                $b3['base12'] += $row['base12'];
                $b3['vat12']  += $row['vat12'];
            }
        }
        return [$b2, $b3];
    }

    /**
     * Reverse charge vystavené (sekce A.1) — kódy s is_reverse_charge=1.
     *
     * @return list<array<string,mixed>>
     */
    private function collectReverseChargeIssued(int $supplierId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare("
            SELECT i.id, i.varsymbol, COALESCE(i.tax_date, i.issue_date) AS tax_date,
                   i.total_without_vat AS base, c.dic AS counterparty_dic
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
              JOIN vat_classifications vc ON vc.code = i.vat_classification_code
             WHERE i.supplier_id = ?
               AND i.status NOT IN ('draft', 'cancelled')
               AND i.invoice_type != 'proforma'
               AND vc.is_reverse_charge = 1
               AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$supplierId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Pojďme cleanDic
        return array_map(fn ($r) => array_merge($r, [
            'counterparty_dic' => $this->cleanDic($r['counterparty_dic']),
            'base'             => (float) $r['base'],
            'vendor_invoice_number' => $r['varsymbol'],  // KH XML používá c_evid_dd
        ]), $rows);
    }

    /**
     * Reverse charge přijaté (sekce B.1) — kódy s is_reverse_charge=1.
     */
    private function collectReverseChargePurchases(int $supplierId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare("
            SELECT pi.id, pi.vendor_invoice_number, COALESCE(pi.tax_date, pi.issue_date) AS tax_date,
                   pi.total_without_vat AS base, c.dic AS counterparty_dic
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
              JOIN vat_classifications vc ON vc.code = pi.vat_classification_code
             WHERE pi.supplier_id = ?
               AND pi.status NOT IN ('draft', 'cancelled')
               AND vc.is_reverse_charge = 1
               AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$supplierId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn ($r) => array_merge($r, [
            'counterparty_dic' => $this->cleanDic($r['counterparty_dic']),
            'base'             => (float) $r['base'],
        ]), $rows);
    }

    /**
     * VAT breakdown per řádek faktury — sum total_without_vat / total_vat per rate.
     *
     * @return array{base21:float, vat21:float, base12:float, vat12:float}
     */
    private function loadInvoiceVatBreakdown(int $id, string $type): array
    {
        $table = $type === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
        $fk = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT vat_rate_snapshot, SUM(total_without_vat) AS base, SUM(total_vat) AS vat
               FROM {$table} WHERE {$fk} = ? GROUP BY vat_rate_snapshot"
        );
        $stmt->execute([$id]);
        $result = ['base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rate = (float) $r['vat_rate_snapshot'];
            if (abs($rate - 21.0) < 0.5) {
                $result['base21'] = (float) $r['base'];
                $result['vat21']  = (float) $r['vat'];
            } elseif (abs($rate - 12.0) < 0.5) {
                $result['base12'] = (float) $r['base'];
                $result['vat12']  = (float) $r['vat'];
            }
        }
        return $result;
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
