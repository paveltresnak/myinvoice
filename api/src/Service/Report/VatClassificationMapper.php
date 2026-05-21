<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Mapper VAT klasifikací — code → dphdp3_line, kh_section, sazba.
 *
 * Pro každého tenanta načte:
 *   - Globální seed kódy (supplier_id IS NULL)
 *   - Per-tenant override (supplier_id = $supplierId) — pokud existuje, vyhraje
 */
final class VatClassificationMapper
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Monthly DPH trend za posledních N měsíců (default 12). Z crm_monthly_summary
     * (pre-aggregated, rychlé).
     *
     * Filter currency=CZK — DPH přiznání je vždy v CZK; sumovat EUR + CZK by dalo
     * nesmyslné hodnoty.
     *
     * @return list<array{period:string, vat_output:float, vat_input:float, vat_due:float}>
     */
    public function monthlyDphTrend(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m');
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_ym, vat_output, vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ? AND period_ym >= ? AND currency = 'CZK'
           ORDER BY period_ym ASC"
        );
        $stmt->execute([$supplierId, $start]);
        return array_map(function ($r) {
            $out = (float) $r['vat_output'];
            $in  = (float) $r['vat_input'];
            return [
                'period'     => (string) $r['period_ym'],
                'vat_output' => $out,
                'vat_input'  => $in,
                'vat_due'    => $out - $in,
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Vrátí mapu code → {label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge}
     *
     * @return array<string, array{label:string, direction:string, dphdp3_line:?string,
     *                              kh_section:?string, vat_rate:?float, is_reverse_charge:bool}>
     */
    public function loadMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge
               FROM vat_classifications
              WHERE (supplier_id IS NULL OR supplier_id = ?)
                AND archived = 0
           ORDER BY supplier_id IS NULL ASC, display_order ASC'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['code']] = [
                'label'             => (string) $r['label'],
                'direction'         => (string) $r['direction'],
                'dphdp3_line'       => $r['dphdp3_line'] !== null ? (string) $r['dphdp3_line'] : null,
                'kh_section'        => $r['kh_section'] !== null ? (string) $r['kh_section'] : null,
                'vat_rate'          => $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null,
                'is_reverse_charge' => (bool) $r['is_reverse_charge'],
            ];
        }
        return $map;
    }

    /**
     * Aggregace pro DPH přiznání DPHDP3 — vrátí summary per řádek výkazu.
     *
     * Z invoices + purchase_invoices + their items podle období (rok+měsíc nebo kvartál).
     * Quarterly: $month = 0 (Q1 = leden-březen pro $year) nebo 3/6/9/12 (poslední měsíc kvartálu).
     * Pro každou fakturu/řádek najde vat_classification_code (item-level override → invoice-level fallback).
     *
     * @param int $year     Rok (např. 2026)
     * @param int $month    Měsíc (1-12) nebo 0 (= roční přehled)
     * @param string $period 'monthly' | 'quarterly' — quarterly bere celý kvartál
     *                       odpovídající danému $month (Q = ceil($month / 3))
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    public function aggregateForDphPriznani(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $map = $this->loadMap($supplierId);
        // Quarterly: spočítej kvartál (1-4) z měsíce + rozsah
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $qStartMonth = ($quarter - 1) * 3 + 1; // 1, 4, 7, 10
            $qEndMonth   = $quarter * 3;            // 3, 6, 9, 12
            $start = sprintf('%04d-%02d-01', $year, $qStartMonth);
            $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $qEndMonth)))
                ->modify('last day of this month')->format('Y-m-d');
        } else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        }

        $byLine = [];
        // Vystavené (revenue side)
        $rows = $this->db->pdo()->prepare(
            "SELECT
                  COALESCE(ii.vat_classification_code, i.vat_classification_code) AS code,
                  SUM(COALESCE(ii.total_without_vat, 0)) AS base_total,
                  SUM(COALESCE(ii.total_vat, 0))         AS vat_total,
                  COUNT(DISTINCT i.id) AS inv_count
             FROM invoices i
             JOIN invoice_items ii ON ii.invoice_id = i.id
            WHERE i.supplier_id = ?
              AND i.status NOT IN ('draft', 'cancelled')
              AND i.invoice_type != 'proforma'
              AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
         GROUP BY code"
        );
        $rows->execute([$supplierId, $start, $end]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $code = $r['code'];
            if (!$code) continue;
            $clsf = $map[$code] ?? null;
            if ($clsf === null || $clsf['dphdp3_line'] === null) continue;
            $line = $clsf['dphdp3_line'];
            if (!isset($byLine[$line])) {
                $byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $clsf['label']];
            }
            $byLine[$line]['base'] += (float) $r['base_total'];
            $byLine[$line]['vat']  += (float) $r['vat_total'];
            $byLine[$line]['count'] += (int) $r['inv_count'];
        }

        // Přijaté (cost side — nárok na odpočet)
        $rows = $this->db->pdo()->prepare(
            "SELECT
                  COALESCE(pii.vat_classification_code, pi.vat_classification_code) AS code,
                  SUM(COALESCE(pii.total_without_vat, 0)) AS base_total,
                  SUM(COALESCE(pii.total_vat, 0))         AS vat_total,
                  COUNT(DISTINCT pi.id) AS inv_count
             FROM purchase_invoices pi
             JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
            WHERE pi.supplier_id = ?
              AND pi.status NOT IN ('draft', 'cancelled')
              AND GREATEST(pi.tax_date, pi.issue_date) BETWEEN ? AND ?
         GROUP BY code"
        );
        $rows->execute([$supplierId, $start, $end]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $code = $r['code'];
            if (!$code) continue;
            $clsf = $map[$code] ?? null;
            if ($clsf === null || $clsf['dphdp3_line'] === null) continue;
            $line = $clsf['dphdp3_line'];
            if (!isset($byLine[$line])) {
                $byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $clsf['label']];
            }
            $byLine[$line]['base'] += (float) $r['base_total'];
            $byLine[$line]['vat']  += (float) $r['vat_total'];
            $byLine[$line]['count'] += (int) $r['inv_count'];
        }

        return $byLine;
    }
}
