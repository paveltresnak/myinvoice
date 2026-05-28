<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Kanonický producent VAT řádků — JEDNO místo se sdílenou logikou pro všechny
 * tři daňové reporty (DPH přiznání, kontrolní hlášení, Kniha DPH):
 *
 *   - filtr období (daňově korektní zařazení do měsíce):
 *       * vystavené → COALESCE(tax_date, issue_date) = DUZP (daň na výstupu k DUZP),
 *       * přijaté → GREATEST(DUZP, vystavení) — nárok na odpočet nejdřív, když plátce
 *         drží daňový doklad (§ 73 ZDPH); zpětné DUZP tak padne do měsíce vystavení.
 *     (Zobrazený sloupec tax_date dál nese skutečné DUZP, mění se jen příslušnost k období.)
 *   - filtr stavu: bez 'cancelled'; 'draft' jen pokud $includeDrafts (Kniha ano,
 *     DPH/KH ne); u vystavených navíc bez 'proforma', u přijatých bez 'advance'
 *     (zálohová/proforma není daňový doklad)
 *   - resolve klasifikačního kódu: řádek → hlavička → auto-default dle sazby + RC + směru
 *   - přepočet na CZK kurzem faktury
 *   - RC samovyměření (jen přijaté): když pii.total_vat=0 a (reverse_charge flag NEBO
 *     is_reverse_charge kódu) → daň = základ × sazba/100
 *
 * Vrací per-(faktura, řádek) řádky; jednotlivé reporty si je projektují:
 *   - DPHDP3 / Kniha DPH: group by dphdp3_line
 *   - KH: group by faktura → sekce dle kh_section + práh + DIČ
 *
 * @phpstan-type LedgerRow array{
 *   source:string, invoice_id:int, doc_number:?string, vendor_invoice_number:?string,
 *   document_kind:?string, status:string, is_draft:bool, tax_date:?string, issue_date:?string,
 *   counterparty_name:string, counterparty_dic:?string, country_iso2:?string,
 *   code:?string, dphdp3_line:?string, dphdp3_line_secondary:?string, kh_section:?string,
 *   is_reverse_charge:bool, vat_deduction_partial:bool, vat_rate:float, base_czk:float, vat_czk:float,
 *   total_with_vat_czk:float, is_fixed_asset:bool, exchange_rate:float
 * }
 */
final class VatLedgerService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>> kanonické řádky (sale i purchase) za období
     */
    public function rows(int $supplierId, string $start, string $end, bool $includeDrafts = false): array
    {
        $map = $this->classificationMap($supplierId);
        $rows = [];
        foreach ($this->fetchSales($supplierId, $start, $end, $includeDrafts) as $r) {
            $rows[] = $this->normalize($r, 'sale', $map);
        }
        foreach ($this->fetchPurchases($supplierId, $start, $end, $includeDrafts) as $r) {
            $rows[] = $this->normalize($r, 'purchase', $map);
        }
        return $rows;
    }

    /**
     * Klasifikační mapa code → atributy (globální seed + per-tenant override).
     *
     * @return array<string, array{dphdp3_line:?string, dphdp3_line_secondary:?string,
     *                              kh_section:?string, vat_rate:?float, is_reverse_charge:bool}>
     */
    public function classificationMap(int $supplierId): array
    {
        // ORDER BY supplier_id IS NULL DESC → globální (NULL) řádky první, per-tenant
        // override poslední → v loopu přepíše globální seed (per-tenant override VYHRAJE).
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, label, dphdp3_line, dphdp3_line_secondary, kh_section, vat_rate, is_reverse_charge
               FROM vat_classifications
              WHERE (supplier_id IS NULL OR supplier_id = ?)
                AND archived = 0
           ORDER BY supplier_id IS NULL DESC, display_order ASC'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['code']] = [
                'label'                 => (string) $r['label'],
                'dphdp3_line'           => $r['dphdp3_line'] !== null ? (string) $r['dphdp3_line'] : null,
                'dphdp3_line_secondary' => $r['dphdp3_line_secondary'] !== null ? (string) $r['dphdp3_line_secondary'] : null,
                'kh_section'            => $r['kh_section'] !== null ? (string) $r['kh_section'] : null,
                'vat_rate'              => $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null,
                'is_reverse_charge'     => (bool) $r['is_reverse_charge'],
            ];
        }
        return $map;
    }

    /** @return list<array<string,mixed>> */
    private function fetchSales(int $supplierId, string $start, string $end, bool $includeDrafts): array
    {
        $statusFilter = $includeDrafts ? "i.status != 'cancelled'" : "i.status NOT IN ('draft', 'cancelled')";
        $stmt = $this->db->pdo()->prepare("
            SELECT i.id AS invoice_id, i.varsymbol AS doc_number, i.varsymbol AS vendor_invoice_number,
                   i.invoice_type AS document_kind, i.status,
                   COALESCE(i.tax_date, i.issue_date) AS tax_date, i.issue_date,
                   COALESCE(i.exchange_rate, 1) AS exchange_rate, COALESCE(cur.code, 'CZK') AS currency,
                   i.total_with_vat AS inv_total, i.reverse_charge AS rc_flag,
                   c.company_name AS counterparty_name, c.dic AS counterparty_dic,
                   co.iso2 AS country_iso2, COALESCE(co.is_eu, 0) AS country_is_eu,
                   0 AS is_fixed_asset,
                   COALESCE(
                       ii.vat_classification_code, i.vat_classification_code,
                       CASE
                           WHEN i.reverse_charge = 1 THEN '20'
                           WHEN ii.vat_rate_snapshot >= 20.5 THEN '1'
                           WHEN ii.vat_rate_snapshot > 0     THEN '2'
                           WHEN ii.vat_rate_snapshot = 0     THEN '3'
                           ELSE NULL
                       END
                   ) AS code,
                   ii.vat_rate_snapshot AS vat_rate,
                   ii.description AS description,
                   COALESCE(ii.total_without_vat, 0) AS base,
                   COALESCE(ii.total_vat, 0) AS vat
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
         LEFT JOIN countries co ON co.id = c.country_id
              JOIN invoice_items ii ON ii.invoice_id = i.id
         LEFT JOIN currencies cur ON cur.id = i.currency_id
             WHERE i.supplier_id = ?
               AND {$statusFilter}
               AND i.invoice_type != 'proforma'
               AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
          ORDER BY COALESCE(i.tax_date, i.issue_date), i.id, ii.id
        ");
        $stmt->execute([$supplierId, $start, $end]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Pozn. k odpočtu DPH na vstupu:
     *  - `vat_deduction = 'none'` (bez nároku — reprezentace, osobní spotřeba…) → do DPH
     *    evidence se VŮBEC nezahrnuje (ani Kniha DPH, ani DPHDP3, ani KH), jen účetní náklad.
     *  - `vat_deduction = 'proportional'` = **poměrný odpočet podle § 75** (vstup zčásti pro
     *    ekonomickou, zčásti pro neekonomickou činnost) → základ i daň se krátí na
     *    `vat_deduction_percent` (viz normalize()). Tyto řádky se v KH označí `pomer='A'`.
     *  - **Krácený nárok § 76** (vypořádací koeficient u plnění osvobozených bez nároku,
     *    ř. 52/53 DPHDP3) implementovaný NENÍ — řeší se ručně / v účetním SW.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchPurchases(int $supplierId, string $start, string $end, bool $includeDrafts): array
    {
        $statusFilter = $includeDrafts ? "pi.status != 'cancelled'" : "pi.status NOT IN ('draft', 'cancelled')";
        $stmt = $this->db->pdo()->prepare("
            SELECT pi.id AS invoice_id, pi.varsymbol AS doc_number, pi.vendor_invoice_number,
                   pi.document_kind, pi.status,
                   COALESCE(pi.tax_date, pi.issue_date) AS tax_date, pi.issue_date,
                   COALESCE(pi.exchange_rate, 1) AS exchange_rate, COALESCE(cur.code, 'CZK') AS currency,
                   pi.total_with_vat AS inv_total, pi.reverse_charge AS rc_flag,
                   pi.vat_deduction, pi.vat_deduction_percent,
                   c.company_name AS counterparty_name, c.dic AS counterparty_dic,
                   co.iso2 AS country_iso2, COALESCE(co.is_eu, 0) AS country_is_eu,
                   (CASE WHEN pii.is_fixed_asset = 1 OR pi.is_fixed_asset = 1 THEN 1 ELSE 0 END) AS is_fixed_asset,
                   COALESCE(
                       pii.vat_classification_code, pi.vat_classification_code,
                       CASE
                           WHEN pi.reverse_charge = 1 THEN '5'
                           WHEN pii.vat_rate_snapshot >= 20.5 THEN '40'
                           WHEN pii.vat_rate_snapshot > 0     THEN '41'
                           ELSE NULL
                       END
                   ) AS code,
                   pii.vat_rate_snapshot AS vat_rate,
                   pii.description AS description,
                   COALESCE(pii.total_without_vat, 0) AS base,
                   COALESCE(pii.total_vat, 0) AS vat
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
         LEFT JOIN countries co ON co.id = c.country_id
              JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND {$statusFilter}
               -- Zálohová / proforma (advance) NENÍ daňový doklad → ven z DPH evidence,
               -- symetricky k výstupní straně (fetchSales: invoice_type != 'proforma').
               -- Daňovým dokladem je až 'daňový doklad k přijaté platbě', ne tato výzva k platbě.
               -- COALESCE: NULL document_kind (legacy / neimportované doklady) = běžný
               -- doklad → ponechat (NULL <> 'advance' by jinak řádek vyřadilo).
               AND COALESCE(pi.document_kind, '') <> 'advance'
               AND pi.vat_deduction <> 'none'
               -- Období odpočtu = pozdější z (DUZP, vystavení). Nárok na odpočet nelze
               -- uplatnit dřív, než plátce drží daňový doklad (§ 73 odst. 2 ZDPH), takže
               -- faktura se zpětným DUZP, ale vystavená v pozdějším měsíci, spadá do
               -- měsíce vystavení. (Zobrazený sloupec tax_date dál ukazuje skutečné DUZP.)
               --
               -- Pozn.: striktně dle § 73 je rozhodující datum, kdy plátce doklad fyzicky
               -- DRŽÍ (= received_at). Záměrně používáme issue_date jako proxy, protože
               -- received_at importy (iDoklad/Fakturoid/ISDOC/AI) plní na den importu —
               -- u zpětně importovaných dokladů by received_at naházel veškerý odpočet do
               -- měsíce importu. issue_date (datum vystavení dodavatelem) je spolehlivé a
               -- pro běžný případ ≈ datum přijetí. Pokud bude k dispozici důvěryhodné datum
               -- přijetí, lze přejít na GREATEST(DUZP, received_at).
               -- CASE místo GREATEST kvůli přenositelnosti (SQLite v testech GREATEST nemá).
               AND CASE
                       WHEN pi.tax_date IS NULL THEN pi.issue_date
                       WHEN pi.issue_date IS NULL THEN pi.tax_date
                       WHEN pi.tax_date >= pi.issue_date THEN pi.tax_date
                       ELSE pi.issue_date
                   END BETWEEN ? AND ?
          ORDER BY CASE
                       WHEN pi.tax_date IS NULL THEN pi.issue_date
                       WHEN pi.issue_date IS NULL THEN pi.tax_date
                       WHEN pi.tax_date >= pi.issue_date THEN pi.tax_date
                       ELSE pi.issue_date
                   END, pi.id, pii.id
        ");
        $stmt->execute([$supplierId, $start, $end]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string, array<string,mixed>> $map
     * @return array<string,mixed>
     */
    private function normalize(array $r, string $source, array $map): array
    {
        $rate = ($r['currency'] === 'CZK' || !$r['exchange_rate']) ? 1.0 : (float) $r['exchange_rate'];
        $vatRate = (float) $r['vat_rate'];
        $baseRaw = (float) $r['base'];
        $vatRaw = (float) $r['vat'];

        $code = $r['code'] !== null ? (string) $r['code'] : null;
        $clsf = $code !== null ? ($map[$code] ?? null) : null;
        $isRc = ($clsf['is_reverse_charge'] ?? false) || (bool) $r['rc_flag'];

        // RC samovyměření jen u přijatých plnění (vendor fakturuje bez DPH).
        if ($source === 'purchase' && $vatRaw == 0.0 && $isRc && $vatRate > 0) {
            $vatRaw = round($baseRaw * $vatRate / 100, 2);
        }

        // §75 poměrný odpočet — u přijatých s 'proportional' se odpočet (základ i daň)
        // uplatní jen v poměrné výši (vat_deduction_percent). Zbytek je nedaňová část
        // mimo DPH přiznání. 'full'/'none' se sem nedostanou (none je odfiltrováno v SQL).
        $isPartialDeduction = false;
        if ($source === 'purchase' && ($r['vat_deduction'] ?? 'full') === 'proportional') {
            $pct = max(0.0, min(100.0, (float) ($r['vat_deduction_percent'] ?? 100))) / 100.0;
            $baseRaw = round($baseRaw * $pct, 2);
            $vatRaw  = round($vatRaw * $pct, 2);
            $isPartialDeduction = true;
        }

        return [
            'source'                => $source,
            'invoice_id'            => (int) $r['invoice_id'],
            'doc_number'            => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
            'status'                => (string) $r['status'],
            'is_draft'              => $r['status'] === 'draft',
            'tax_date'              => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'counterparty_name'     => (string) ($r['counterparty_name'] ?? ''),
            'counterparty_dic'      => $r['counterparty_dic'] !== null ? (string) $r['counterparty_dic'] : null,
            'country_iso2'          => $r['country_iso2'] !== null ? strtoupper((string) $r['country_iso2']) : null,
            'country_is_eu'         => (bool) $r['country_is_eu'],
            'description'           => (string) ($r['description'] ?? ''),
            'label'                 => $clsf['label'] ?? '',
            'code'                  => $code,
            'dphdp3_line'           => $clsf['dphdp3_line'] ?? null,
            'dphdp3_line_secondary' => $clsf['dphdp3_line_secondary'] ?? null,
            'kh_section'            => $clsf['kh_section'] ?? null,
            'is_reverse_charge'     => $isRc,
            'vat_deduction_partial' => $isPartialDeduction,
            'vat_rate'              => $vatRate,
            'currency'              => (string) $r['currency'],
            'base_czk'              => round($baseRaw * $rate, 2),
            'vat_czk'               => round($vatRaw * $rate, 2),
            'total_with_vat_czk'    => round((float) $r['inv_total'] * $rate, 2),
            'is_fixed_asset'        => (bool) $r['is_fixed_asset'],
            'exchange_rate'         => $rate,
        ];
    }
}
