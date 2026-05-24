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
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
    ) {}

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
        // Období (měsíc / kvartál) → rozsah dat.
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

        // Projekce kanonických řádků (VatLedgerService) na řádky DPHDP3. Sdílená logika
        // (klasifikace, CZK, RC samovyměření, rate bucket) žije ve službě; tady jen
        // agregujeme po dphdp3_line + mirror ř.43 (secondary) + ř.47 (majetek).
        $byLine = [];
        $invoiceLineSeen = []; // per (source:invId) × line → distinct count
        foreach ($this->ledger->rows($supplierId, $start, $end, includeDrafts: false) as $r) {
            $primary = $r['dphdp3_line'];
            if ($r['code'] === null || $primary === null) continue; // bez řádku DPHDP3 → přeskoč

            $baseCzk = (float) $r['base_czk'];
            $vatCzk  = (float) $r['vat_czk'];
            $label   = (string) $r['label'];
            // Count distinct faktur per řádek: oddělený namespace sale/purchase.
            $invId = (int) $r['invoice_id'] * 10 + ($r['source'] === 'sale' ? 1 : 2);

            $this->addLine($byLine, $primary, $baseCzk, $vatCzk, $invId, $invoiceLineSeen, $label);

            // Secondary (typicky ř.43 — mirror odpočet u RC / dovozu služby).
            $secondary = $r['dphdp3_line_secondary'];
            if ($secondary !== null && $secondary !== '' && $secondary !== $primary) {
                $this->addLine($byLine, $secondary, $baseCzk, $vatCzk, $invId, $invoiceLineSeen, $label);
            }

            // ř.47 — hodnota pořízeného majetku (doplňující údaj k ř.40-45).
            if ($r['is_fixed_asset']) {
                $assetEligibleLine = $this->countsAsFixedAssetLine($primary)
                    ? $primary
                    : (($secondary !== null && $this->countsAsFixedAssetLine($secondary)) ? $secondary : null);
                if ($assetEligibleLine !== null) {
                    $this->addLine($byLine, '47', $baseCzk, $vatCzk, $invId, $invoiceLineSeen, 'Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)');
                }
            }
        }

        return $byLine;
    }

    /**
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $byLine by-ref
     * @param array<string, bool> $invoiceLineSeen by-ref
     */
    private function addLine(array &$byLine, string $line, float $baseCzk, float $vatCzk, int $invId, array &$invoiceLineSeen, string $label): void
    {
        if (!isset($byLine[$line])) {
            $byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $label];
        }
        $byLine[$line]['base'] += $baseCzk;
        $byLine[$line]['vat']  += $vatCzk;
        $seenKey = $invId . ':' . $line;
        if (!isset($invoiceLineSeen[$seenKey])) {
            $invoiceLineSeen[$seenKey] = true;
            $byLine[$line]['count']++;
        }
    }

    /**
     * Smí dané plnění figurovat na ř. 47 (hodnota pořízeného majetku)?
     *
     * Doplňující údaj k odpočtu — vstup do ř. 40-45 (tuzemsko 40/41, dovoz CÚ 42,
     * RC mirror 43, korekce 44, registrace 45). NE pro výstupové řádky 3-13
     * samotné (ty se počítají odděleně přes secondary='43' mirror).
     */
    private function countsAsFixedAssetLine(string $primaryLine): bool
    {
        $n = (int) $primaryLine;
        return $n >= 40 && $n <= 45;
    }
}
