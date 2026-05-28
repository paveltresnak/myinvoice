<?php

declare(strict_types=1);

namespace MyInvoice\Service\Crm;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * CRM dashboard aggregation queries.
 *
 * Čte z `crm_monthly_summary` (pre-aggregated přes sp_recompute_crm_monthly_summary).
 * Plus live queries pro top klienti/vendoři (z invoices/purchase_invoices direct).
 *
 * Period filters:
 *   - 'current_month' / 'last_month' / 'ytd' (year-to-date) / 'last_12m'
 *
 * Multi-currency: vrací breakdown per currency. UI nabídne CurrencyPicker.
 */
final class CrmAggregationService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Volá sp_recompute_crm_monthly_summary pro daný tenant.
     * Manuálně z admin UI nebo z cron jobu.
     */
    public function recompute(int $supplierId): void
    {
        $this->db->pdo()->prepare('CALL sp_recompute_crm_monthly_summary(?)')->execute([$supplierId]);
    }

    /**
     * Lazy recompute — pokud je crm_monthly_summary starší než $maxAgeSec sekund
     * (nebo prázdná), spustí sp_recompute_crm_monthly_summary.
     * Default 300s = 5 min staleness window.
     */
    public function recomputeIfStale(int $supplierId, int $maxAgeSec = 300): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(computed_at) FROM crm_monthly_summary WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $lastComputed = $stmt->fetchColumn();
        $needsRecompute = $lastComputed === null || $lastComputed === false
            || (time() - (int) strtotime((string) $lastComputed)) > $maxAgeSec;
        if ($needsRecompute) {
            $this->recompute($supplierId);
            return true;
        }
        return false;
    }

    /**
     * Overview KPI: aktuální měsíc + minulý měsíc + YTD (per currency).
     *
     * @return array{
     *   current_month: array<int, array<string,mixed>>,
     *   last_month: array<int, array<string,mixed>>,
     *   ytd: array<int, array<string,mixed>>,
     *   currencies: list<string>
     * }
     */
    public function overview(int $supplierId): array
    {
        // Auto-recompute pokud je summary starší než 5 minut — odpadá ruční klik "Přepočítat"
        // po každém importu/úpravě faktury. sp je rychlá (~ms), tenant-scoped.
        $this->recomputeIfStale($supplierId);

        $now = new \DateTimeImmutable();
        $currentMonth = $now->format('Y-m');
        $lastMonth = $now->modify('-1 month')->format('Y-m');
        $yearStart = $now->format('Y-01');

        return [
            'current_month' => $this->loadMonth($supplierId, $currentMonth),
            'last_month'    => $this->loadMonth($supplierId, $lastMonth),
            'ytd'           => $this->loadRange($supplierId, $yearStart, $currentMonth),
            'currencies'    => $this->listCurrencies($supplierId),
        ];
    }

    /**
     * Měsíční breakdown za posledních N měsíců (default 12). Per currency.
     *
     * @return list<array{period:string, currency:string, revenue:float, costs:float,
     *                    profit:float, invoice_count:int, purchase_count:int}>
     */
    public function monthlyHistory(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m');
        $params = [$supplierId, $start];
        $where = ' AND period_ym >= ?';
        if ($currency !== null) {
            $where .= ' AND currency = ?';
            $params[] = $currency;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_ym, currency, revenue, revenue_net, costs, costs_net,
                    invoice_count, purchase_count, vat_output, vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ?{$where}
           ORDER BY period_ym ASC, currency ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $rev = (float) $r['revenue'];
            $costs = (float) $r['costs'];
            return [
                'period'          => (string) $r['period_ym'],
                'currency'        => (string) $r['currency'],
                'revenue'         => $rev,
                'revenue_net'     => (float) $r['revenue_net'],
                'costs'           => $costs,
                'costs_net'       => (float) $r['costs_net'],
                'profit'          => $rev - $costs,
                'invoice_count'   => (int) $r['invoice_count'],
                'purchase_count'  => (int) $r['purchase_count'],
                'vat_output'      => (float) $r['vat_output'],
                'vat_input'       => (float) $r['vat_input'],
            ];
        }, $rows);
    }

    /**
     * Top klienti by revenue za posledních N měsíců.
     *
     * @return list<array{client_id:int, company_name:string, revenue:float,
     *                    invoice_count:int, currency:string, percent_share:float}>
     */
    public function topClients(int $supplierId, int $monthsBack = 12, int $limit = 10, ?string $currency = null): array
    {
        // CZK přepočet přes i.exchange_rate — multi-currency klienti se neroztrhnou na N řádků
        // a ranking je správný napříč měnami (1000 EUR > 20 000 CZK).
        // Parametr $currency zachován pro BC (ignoruje se — vždy ranking v CZK).
        unset($currency);
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-01');
        $params = [$supplierId, $start];
        $sql = "
            SELECT i.client_id, c.company_name,
                   SUM(COALESCE(i.total_with_vat, 0) * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_czk,
                   COUNT(*) AS invoice_count,
                   GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                   SUM(SUM(COALESCE(i.total_with_vat, 0) * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1))) OVER () AS total_all
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
              JOIN currencies cur ON cur.id = i.currency_id
             WHERE i.supplier_id = ?
               AND i.issue_date >= ?
               AND i.status NOT IN ('draft', 'cancelled')
               AND i.invoice_type != 'proforma'
          GROUP BY i.client_id, c.company_name
          ORDER BY revenue_czk DESC
             LIMIT " . (int) $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $rev = (float) $r['revenue_czk'];
            $total = (float) $r['total_all'];
            return [
                'client_id'     => (int) $r['client_id'],
                'company_name'  => (string) $r['company_name'],
                'revenue'       => $rev,
                'invoice_count' => (int) $r['invoice_count'],
                'currency'      => 'CZK',
                'currencies'    => (string) $r['currencies'],
                'percent_share' => $total > 0 ? round(($rev / $total) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Yearly aggregation per currency (revenue + costs + profit + counts).
     * Vrací všechny roky, kde tenant měl aspoň jednu řádku v crm_monthly_summary.
     *
     * @return list<array{year:int, currency:string, revenue:float, costs:float, profit:float, invoice_count:int, purchase_count:int}>
     */
    public function yearlyHistory(int $supplierId, ?string $currency = null): array
    {
        $params = [$supplierId];
        $where = '';
        if ($currency !== null) {
            $where = ' AND currency = ?';
            $params[] = $currency;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT SUBSTRING(period_ym, 1, 4) AS year, currency,
                    SUM(revenue)        AS revenue,
                    SUM(costs)          AS costs,
                    SUM(invoice_count)  AS invoice_count,
                    SUM(purchase_count) AS purchase_count
               FROM crm_monthly_summary
              WHERE supplier_id = ?{$where}
           GROUP BY year, currency
           ORDER BY year DESC, currency ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static function ($r) {
            $rev = (float) $r['revenue'];
            $costs = (float) $r['costs'];
            return [
                'year'           => (int) $r['year'],
                'currency'       => (string) $r['currency'],
                'revenue'        => $rev,
                'costs'          => $costs,
                'profit'         => $rev - $costs,
                'invoice_count'  => (int) $r['invoice_count'],
                'purchase_count' => (int) $r['purchase_count'],
            ];
        }, $rows);
    }

    /**
     * Top vendors by costs.
     */
    public function topVendors(int $supplierId, int $monthsBack = 12, int $limit = 10, ?string $currency = null): array
    {
        // CZK přepočet přes pi.exchange_rate — multi-currency vendor se neroztrhne.
        // Parametr $currency zachován pro BC (ignoruje se — vždy ranking v CZK).
        unset($currency);
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-01');
        $params = [$supplierId, $start];
        $sql = "
            SELECT pi.vendor_id, c.company_name,
                   SUM(COALESCE(pi.total_with_vat, 0) * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_czk,
                   COUNT(*) AS purchase_count,
                   GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                   SUM(SUM(COALESCE(pi.total_with_vat, 0) * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1))) OVER () AS total_all
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.issue_date >= ?
               AND pi.status NOT IN ('draft', 'cancelled')
               -- Spárovaná/zaplacená záloha (advance) nese náklad finální faktura → vyřadit
               AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                        AND (pi.status = 'paid'
                             OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                         WHERE adv_s.advance_purchase_invoice_id = pi.id)))
          GROUP BY pi.vendor_id, c.company_name
          ORDER BY costs_czk DESC
             LIMIT " . (int) $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $costs = (float) $r['costs_czk'];
            $total = (float) $r['total_all'];
            return [
                'vendor_id'      => (int) $r['vendor_id'],
                'company_name'   => (string) $r['company_name'],
                'costs'          => $costs,
                'purchase_count' => (int) $r['purchase_count'],
                'currency'       => 'CZK',
                'currencies'     => (string) ($r['currencies'] ?? 'CZK'),
                'percent_share'  => $total > 0 ? round(($costs / $total) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadMonth(int $supplierId, string $periodYm): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_ym, currency, revenue, revenue_net, costs, costs_net,
                    invoice_count, purchase_count, vat_output, vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ? AND period_ym = ?
           ORDER BY currency ASC"
        );
        $stmt->execute([$supplierId, $periodYm]);
        return array_map(fn ($r) => $this->castSummary($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRange(int $supplierId, string $fromYm, string $toYm): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT currency,
                    SUM(revenue) AS revenue, SUM(revenue_net) AS revenue_net,
                    SUM(costs)   AS costs,   SUM(costs_net)   AS costs_net,
                    SUM(invoice_count) AS invoice_count,
                    SUM(purchase_count) AS purchase_count,
                    SUM(vat_output) AS vat_output, SUM(vat_input) AS vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ? AND period_ym >= ? AND period_ym <= ?
           GROUP BY currency
           ORDER BY currency ASC"
        );
        $stmt->execute([$supplierId, $fromYm, $toYm]);
        return array_map(fn ($r) => $this->castSummary($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function castSummary(array $r): array
    {
        return [
            'period'         => $r['period_ym'] ?? null,
            'currency'       => (string) $r['currency'],
            'revenue'        => (float) $r['revenue'],
            'revenue_net'    => (float) $r['revenue_net'],
            'costs'          => (float) $r['costs'],
            'costs_net'      => (float) $r['costs_net'],
            'profit'         => (float) $r['revenue'] - (float) $r['costs'],
            'invoice_count'  => (int) $r['invoice_count'],
            'purchase_count' => (int) $r['purchase_count'],
            'vat_output'     => (float) $r['vat_output'],
            'vat_input'      => (float) $r['vat_input'],
        ];
    }

    /**
     * @return list<string>
     */
    private function listCurrencies(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT currency FROM crm_monthly_summary WHERE supplier_id = ? ORDER BY currency'
        );
        $stmt->execute([$supplierId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'currency');
    }

    /**
     * Aging buckets pro nezaplacené vystavené faktury.
     * Klasifikuje po splatnosti: not_due, 0-30, 31-60, 61-90, 90+
     *
     * @return list<array{bucket:string, currency:string, count:int, total:float}>
     */
    public function agingReceivables(int $supplierId): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $sql = "
            SELECT
                CASE
                    WHEN i.due_date > ? THEN 'not_due'
                    WHEN DATEDIFF(?, i.due_date) <= 30  THEN 'overdue_30'
                    WHEN DATEDIFF(?, i.due_date) <= 60  THEN 'overdue_60'
                    WHEN DATEDIFF(?, i.due_date) <= 90  THEN 'overdue_90'
                    ELSE 'overdue_90_plus'
                END AS bucket,
                COALESCE(c.code, 'CZK') AS currency,
                COUNT(*) AS cnt,
                SUM(COALESCE(i.total_with_vat, 0)) AS total
              FROM invoices i
         LEFT JOIN currencies c ON c.id = i.currency_id
             WHERE i.supplier_id = ?
               AND i.status IN ('issued', 'sent', 'reminded')
               AND i.invoice_type != 'proforma'
          GROUP BY bucket, currency
          ORDER BY currency, FIELD(bucket, 'not_due', 'overdue_30', 'overdue_60', 'overdue_90', 'overdue_90_plus')
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$today, $today, $today, $today, $supplierId]);
        return array_map(fn ($r) => [
            'bucket'   => (string) $r['bucket'],
            'currency' => (string) $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => (float) $r['total'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Aging buckets pro nezaplacené přijaté faktury (závazky).
     */
    public function agingPayables(int $supplierId): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $sql = "
            SELECT
                CASE
                    WHEN pi.due_date > ? THEN 'not_due'
                    WHEN DATEDIFF(?, pi.due_date) <= 30  THEN 'overdue_30'
                    WHEN DATEDIFF(?, pi.due_date) <= 60  THEN 'overdue_60'
                    WHEN DATEDIFF(?, pi.due_date) <= 90  THEN 'overdue_90'
                    ELSE 'overdue_90_plus'
                END AS bucket,
                COALESCE(c.code, 'CZK') AS currency,
                COUNT(*) AS cnt,
                SUM(COALESCE(pi.amount_to_pay, pi.total_with_vat, 0)) AS total
              FROM purchase_invoices pi
         LEFT JOIN currencies c ON c.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.status IN ('received', 'booked')
          GROUP BY bucket, currency
          ORDER BY currency, FIELD(bucket, 'not_due', 'overdue_30', 'overdue_60', 'overdue_90', 'overdue_90_plus')
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$today, $today, $today, $today, $supplierId]);
        return array_map(fn ($r) => [
            'bucket'   => (string) $r['bucket'],
            'currency' => (string) $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => (float) $r['total'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * DSO (Days Sales Outstanding) za posledních N měsíců.
     * Vrátí průměrný počet dní mezi issue_date a paid_at u zaplacených faktur.
     *
     * @return array{avg_days:float, sample_size:int, period_months:int}
     */
    public function daysSalesOutstanding(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) AS avg_days, COUNT(*) AS sample
               FROM invoices
              WHERE supplier_id = ?
                AND status = 'paid'
                AND paid_at IS NOT NULL
                AND issue_date >= ?
                AND invoice_type != 'proforma'"
        );
        $stmt->execute([$supplierId, $start]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'avg_days'      => round((float) ($row['avg_days'] ?? 0), 1),
            'sample_size'   => (int) ($row['sample'] ?? 0),
            'period_months' => $monthsBack,
        ];
    }

    /**
     * Payment punctuality — % faktur zaplacených včas (před nebo na due_date).
     */
    public function paymentPunctuality(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN paid_at <= due_date THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN paid_at >  due_date THEN 1 ELSE 0 END) AS late,
                COUNT(*) AS total
             FROM invoices
            WHERE supplier_id = ?
              AND status = 'paid'
              AND paid_at IS NOT NULL
              AND issue_date >= ?
              AND invoice_type != 'proforma'"
        );
        $stmt->execute([$supplierId, $start]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        return [
            'on_time'        => (int) ($row['on_time'] ?? 0),
            'late'           => (int) ($row['late'] ?? 0),
            'total'          => $total,
            'on_time_pct'    => $total > 0 ? round((((int) $row['on_time']) / $total) * 100, 1) : 0.0,
            'period_months'  => $monthsBack,
        ];
    }

    /**
     * Concentration risk — % share top klienta v revenue.
     * "Pareto" warning: pokud TOP 1 klient > 40 %, jeden klient > 30 %, …
     */
    public function clientConcentration(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        $clients = $this->topClients($supplierId, $monthsBack, 50, $currency);
        if (empty($clients)) {
            return ['top1_share' => 0, 'top3_share' => 0, 'top5_share' => 0, 'total_clients' => 0,
                    'pareto_80_count' => 0, 'risk_level' => 'low'];
        }
        // Per currency group — vezmu jen first currency (UI volá per měna)
        $cur = $currency ?? $clients[0]['currency'];
        $filtered = array_values(array_filter($clients, fn ($c) => $c['currency'] === $cur));

        $top1 = $filtered[0]['percent_share'] ?? 0;
        $top3 = array_sum(array_slice(array_column($filtered, 'percent_share'), 0, 3));
        $top5 = array_sum(array_slice(array_column($filtered, 'percent_share'), 0, 5));

        // Pareto — kolik klientů dělá 80%
        $pareto80 = 0;
        $cumul = 0;
        foreach ($filtered as $c) {
            $cumul += $c['percent_share'];
            $pareto80++;
            if ($cumul >= 80) break;
        }

        $riskLevel = $top1 > 40 ? 'high' : ($top1 > 25 ? 'medium' : 'low');

        return [
            'top1_share'      => round($top1, 1),
            'top3_share'      => round($top3, 1),
            'top5_share'      => round($top5, 1),
            'total_clients'   => count($filtered),
            'pareto_80_count' => $pareto80,
            'risk_level'      => $riskLevel,
            'currency'        => $cur,
        ];
    }

    /**
     * Vendor concentration risk — % nákladů z top dodavatele.
     * Analogie clientConcentration(), ale nad přijatými fakturami (závislost na dodavateli).
     *
     * @return array{top1_share: float, top3_share: float, top5_share: float, total_vendors: int,
     *               pareto_80_count: int, risk_level: string, currency: string}
     */
    public function vendorConcentration(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        $vendors = $this->topVendors($supplierId, $monthsBack, 50, $currency);
        if (empty($vendors)) {
            return ['top1_share' => 0, 'top3_share' => 0, 'top5_share' => 0, 'total_vendors' => 0,
                    'pareto_80_count' => 0, 'risk_level' => 'low', 'currency' => 'CZK'];
        }
        $cur = $vendors[0]['currency'];

        $top1 = $vendors[0]['percent_share'] ?? 0;
        $top3 = array_sum(array_slice(array_column($vendors, 'percent_share'), 0, 3));
        $top5 = array_sum(array_slice(array_column($vendors, 'percent_share'), 0, 5));

        // Pareto — kolik dodavatelů dělá 80 % nákladů
        $pareto80 = 0;
        $cumul = 0;
        foreach ($vendors as $v) {
            $cumul += $v['percent_share'];
            $pareto80++;
            if ($cumul >= 80) break;
        }

        $riskLevel = $top1 > 40 ? 'high' : ($top1 > 25 ? 'medium' : 'low');

        return [
            'top1_share'      => round($top1, 1),
            'top3_share'      => round($top3, 1),
            'top5_share'      => round($top5, 1),
            'total_vendors'   => count($vendors),
            'pareto_80_count' => $pareto80,
            'risk_level'      => $riskLevel,
            'currency'        => $cur,
        ];
    }

    /**
     * DPO (Days Payable Outstanding) za posledních N měsíců.
     * Průměrný počet dní mezi vystavením přijaté faktury a její úhradou dodavateli.
     * Doplněk k DSO — spolu dávají pracovní kapitálový cyklus (DSO − DPO).
     *
     * @return array{avg_days: float, sample_size: int, period_months: int}
     */
    public function daysPayableOutstanding(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) AS avg_days, COUNT(*) AS sample
               FROM purchase_invoices
              WHERE supplier_id = ?
                AND status = 'paid'
                AND paid_at IS NOT NULL
                AND issue_date >= ?"
        );
        $stmt->execute([$supplierId, $start]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'avg_days'      => round((float) ($row['avg_days'] ?? 0), 1),
            'sample_size'   => (int) ($row['sample'] ?? 0),
            'period_months' => $monthsBack,
        ];
    }

    /**
     * Expense breakdown po kategoriích (vyžaduje expense_categories assignment).
     *
     * @return list<array{category_id:?int, code:?string, label:?string, total:float, count:int, percent:float}>
     */
    public function expenseBreakdown(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        // CZK přepočet — bez něj sumace EUR+CZK ve stejné kategorii dává nesmysl
        // (100 EUR + 50 000 CZK = 50 100). Parametr $currency BC, vždy CZK.
        unset($currency);
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $params = [$supplierId, $start];
        $sql = "
            SELECT pi.expense_category_id, ec.code, ec.label,
                   SUM(COALESCE(pi.total_with_vat, 0) * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)) AS total,
                   COUNT(*) AS cnt
              FROM purchase_invoices pi
         LEFT JOIN expense_categories ec ON ec.id = pi.expense_category_id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.issue_date >= ?
               AND pi.status NOT IN ('draft', 'cancelled')
          GROUP BY pi.expense_category_id, ec.code, ec.label
          ORDER BY total DESC
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $sum = array_sum(array_column($rows, 'total'));
        return array_map(fn ($r) => [
            'category_id' => $r['expense_category_id'] !== null ? (int) $r['expense_category_id'] : null,
            'code'        => $r['code'] !== null ? (string) $r['code'] : null,
            'label'       => $r['label'] !== null ? (string) $r['label'] : null,
            'total'       => (float) $r['total'],
            'count'       => (int) $r['cnt'],
            'percent'     => $sum > 0 ? round(((float) $r['total'] / $sum) * 100, 1) : 0.0,
        ], $rows);
    }

    /**
     * Customer churn risk — klienti, kteří neměli fakturu 60+ dní.
     *
     * @return list<array{client_id:int, company_name:string, last_invoice_date:string,
     *                    days_since:int, total_revenue:float, currency:string}>
     */
    public function churnRisk(int $supplierId, int $thresholdDays = 60, int $limit = 20): array
    {
        // GROUP BY jen po klientovi (ne per currency) — multi-currency klient byl rozdělen
        // do N řádků (jedna měna = jedna pozice v listu, partial revenue). CZK přepočet.
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id AS client_id, c.company_name,
                    MAX(i.issue_date) AS last_invoice_date,
                    DATEDIFF(?, MAX(i.issue_date)) AS days_since,
                    SUM(COALESCE(i.total_with_vat, 0) * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS total_revenue,
                    GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies
               FROM clients c
               JOIN invoices i ON i.client_id = c.id AND i.status NOT IN ('draft', 'cancelled')
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE c.supplier_id = ?
                AND i.invoice_type != 'proforma'
                AND c.is_customer = 1
           GROUP BY c.id, c.company_name
             HAVING DATEDIFF(?, MAX(i.issue_date)) > ?
           ORDER BY days_since ASC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$today, $supplierId, $today, $thresholdDays]);
        return array_map(fn ($r) => [
            'client_id'         => (int) $r['client_id'],
            'company_name'      => (string) $r['company_name'],
            'last_invoice_date' => (string) $r['last_invoice_date'],
            'days_since'        => (int) $r['days_since'],
            'total_revenue'     => (float) $r['total_revenue'],
            'currency'          => 'CZK',
            'currencies'        => (string) ($r['currencies'] ?? 'CZK'),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Action items widget — daily TODO list pro user.
     *
     * Pokud je předán $userId, aplikuje per-user dismissals (day/week/forever/historical).
     * Pro mode=historical: vrátí item jen pokud existuje NOVÉ id, které není v baseline.
     *
     * @return array{
     *   items: list<array{type:string, severity:string, title:string, hint:string, link:string, count?:int, days?:int}>,
     *   total: int
     * }
     */
    public function actionItems(int $supplierId, ?int $userId = null): array
    {
        $items = [];
        $pdo = $this->db->pdo();
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        // Load dismissals once
        $dismissals = $this->loadDismissals($supplierId, $userId);

        // 1. Overdue vystavené faktury — pošli upomínku
        $stmt = $pdo->prepare(
            "SELECT id FROM invoices
              WHERE supplier_id = ?
                AND status IN ('issued', 'sent', 'reminded')
                AND invoice_type != 'proforma'
                AND due_date < ?"
        );
        $stmt->execute([$supplierId, $today]);
        $overdueIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $overdueIds = $this->filterByDismissal($overdueIds, $dismissals, 'overdue_invoices');
        $overdueCount = count($overdueIds);
        if ($overdueCount > 0) {
            $items[] = [
                'type'     => 'overdue_invoices',
                'severity' => $overdueCount > 5 ? 'high' : 'medium',
                'title'    => 'Pošli upomínky',
                'hint'     => sprintf('%d %s po splatnosti', $overdueCount,
                    $overdueCount === 1 ? 'faktura' : ($overdueCount < 5 ? 'faktury' : 'faktur')),
                'link'     => '/invoices?status=overdue',
                'count'    => $overdueCount,
            ];
        }

        // 2. Recurring s next_run_date v <= 3 dnech (nové faktury k vystavení)
        $stmt = $pdo->prepare(
            "SELECT id FROM recurring_invoice_templates
              WHERE supplier_id = ?
                AND (end_date IS NULL OR end_date >= ?)
                AND next_run_date IS NOT NULL
                AND next_run_date <= DATE_ADD(?, INTERVAL 3 DAY)
                AND next_run_date >= ?"
        );
        $stmt->execute([$supplierId, $today, $today, $today]);
        $recurringIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $recurringIds = $this->filterByDismissal($recurringIds, $dismissals, 'recurring_due');
        $recurringCount = count($recurringIds);
        if ($recurringCount > 0) {
            $items[] = [
                'type'     => 'recurring_due',
                'severity' => 'medium',
                'title'    => 'Vystav pravidelné faktury',
                'hint'     => sprintf('%d %s má vystavit v příštích 3 dnech', $recurringCount,
                    $recurringCount === 1 ? 'recurring fakturace' : 'recurring fakturací'),
                'link'     => '/recurring',
                'count'    => $recurringCount,
            ];
        }

        // 3. Přijaté faktury po splatnosti — zaplatit dodavateli
        $stmt = $pdo->prepare(
            "SELECT id FROM purchase_invoices
              WHERE supplier_id = ?
                AND status IN ('received', 'booked')
                AND due_date < ?"
        );
        $stmt->execute([$supplierId, $today]);
        $payablesIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $payablesIds = $this->filterByDismissal($payablesIds, $dismissals, 'overdue_payables');
        $payablesCount = count($payablesIds);
        if ($payablesCount > 0) {
            $items[] = [
                'type'     => 'overdue_payables',
                'severity' => $payablesCount > 3 ? 'high' : 'medium',
                'title'    => 'Zaplať dodavatelům',
                'hint'     => sprintf('%d nezaplacených přijatých %s po splatnosti', $payablesCount,
                    $payablesCount === 1 ? 'faktura' : 'faktur'),
                'link'     => '/purchase-invoices?overdue=1',
                'count'    => $payablesCount,
            ];
        }

        // 4. Reports deadlines — DPH/KH/SH se podávají 25. následujícího měsíce
        // (date-based, historical mode chová se jako forever na aktuální měsíc)
        if (!$this->isFullyDismissed($dismissals, 'tax_deadline')) {
            $now = new \DateTimeImmutable($today);
            $currentMonth = (int) $now->format('n');
            $currentYear = (int) $now->format('Y');
            $deadlineDate = $currentMonth === 1
                ? "{$currentYear}-01-25"
                : sprintf('%04d-%02d-25', $currentYear, $currentMonth);
            $deadlineDt = new \DateTimeImmutable($deadlineDate);
            $daysToDeadline = (int) $now->diff($deadlineDt)->format('%r%a');
            $stmt = $pdo->prepare("SELECT is_vat_payer FROM supplier WHERE id = ?");
            $stmt->execute([$supplierId]);
            $isVatPayer = (bool) $stmt->fetchColumn();
            if ($isVatPayer && $daysToDeadline >= -3 && $daysToDeadline <= 7) {
                $sev = $daysToDeadline < 0 ? 'high' : ($daysToDeadline <= 2 ? 'high' : 'medium');
                $items[] = [
                    'type'     => 'tax_deadline',
                    'severity' => $sev,
                    'title'    => 'DPH + KH za uplynulý měsíc',
                    'hint'     => $daysToDeadline < 0
                        ? sprintf('Termín byl %d dní zpět — podej co nejdříve!', abs($daysToDeadline))
                        : sprintf('Termín podání za %d %s (do %s)', $daysToDeadline,
                            $daysToDeadline === 1 ? 'den' : ($daysToDeadline < 5 ? 'dny' : 'dní'),
                            $deadlineDate),
                    'link'     => '/reports/dph',
                    'days'     => $daysToDeadline,
                ];
            }
        }

        // 5. Klienti dlouho bez objednávky (90+ dní)
        $stmt = $pdo->prepare(
            "WITH last_invoice AS (
                SELECT client_id, MAX(issue_date) AS last_date
                  FROM invoices
                 WHERE supplier_id = ?
                   AND status NOT IN ('draft', 'cancelled')
                 GROUP BY client_id
              )
              SELECT li.client_id FROM last_invoice li
              JOIN clients c ON c.id = li.client_id
             WHERE DATEDIFF(?, li.last_date) >= 90"
        );
        $stmt->execute([$supplierId, $today]);
        $churnIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $churnIds = $this->filterByDismissal($churnIds, $dismissals, 'churn_risk');
        $churnCount = count($churnIds);
        if ($churnCount > 0) {
            $items[] = [
                'type'     => 'churn_risk',
                'severity' => 'low',
                'title'    => 'Kontaktuj neaktivní klienty',
                'hint'     => sprintf('%d %s 90+ dní bez objednávky', $churnCount,
                    $churnCount === 1 ? 'klient je' : 'klientů je'),
                'link'     => '/crm',
                'count'    => $churnCount,
            ];
        }

        return [
            'items' => $items,
            'total' => count($items),
            'dismissed_count' => count($dismissals),
        ];
    }

    /**
     * Vrátí dismissals pro daného user (klíčované item_type).
     * Auto-promaže expirované záznamy day/week.
     *
     * @return array<string, array{mode:string, dismissed_until:?string, baseline_ids:?array<int>}>
     */
    private function loadDismissals(int $supplierId, ?int $userId): array
    {
        if ($userId === null || $userId <= 0) {
            return [];
        }
        $pdo = $this->db->pdo();
        // Auto-cleanup expired temporary dismissals
        $cleanup = $pdo->prepare(
            "DELETE FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ?
                AND mode IN ('day','week')
                AND dismissed_until IS NOT NULL
                AND dismissed_until < NOW()"
        );
        $cleanup->execute([$supplierId, $userId]);

        $stmt = $pdo->prepare(
            "SELECT item_type, mode, dismissed_until, baseline_ids
               FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ?"
        );
        $stmt->execute([$supplierId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $baseline = null;
            if ($row['baseline_ids'] !== null) {
                $decoded = json_decode((string) $row['baseline_ids'], true);
                $baseline = is_array($decoded) ? array_map('intval', $decoded) : null;
            }
            $out[(string) $row['item_type']] = [
                'mode'            => (string) $row['mode'],
                'dismissed_until' => $row['dismissed_until'] !== null ? (string) $row['dismissed_until'] : null,
                'baseline_ids'    => $baseline,
            ];
        }
        return $out;
    }

    /**
     * Vrací jen ID, která mají být zobrazená.
     * - day/week: pokud dismissed_until je v budoucnosti → []
     * - forever: → []
     * - historical: vrátí ID, která NEJSOU v baseline (= "nová")
     *
     * @param list<int> $currentIds
     * @param array<string, array{mode:string, dismissed_until:?string, baseline_ids:?array<int>}> $dismissals
     * @return list<int>
     */
    private function filterByDismissal(array $currentIds, array $dismissals, string $itemType): array
    {
        if (!isset($dismissals[$itemType])) {
            return $currentIds;
        }
        $d = $dismissals[$itemType];
        $mode = $d['mode'];
        if ($mode === 'day' || $mode === 'week') {
            // dismissed_until v budoucnosti = hide; jinak by byl už vyčištěn loadDismissals
            if ($d['dismissed_until'] !== null && strtotime($d['dismissed_until']) > time()) {
                return [];
            }
            return $currentIds;
        }
        if ($mode === 'forever') {
            return [];
        }
        if ($mode === 'historical') {
            $baseline = $d['baseline_ids'] ?? [];
            $baselineSet = array_flip($baseline);
            return array_values(array_filter($currentIds, static fn(int $id) => !isset($baselineSet[$id])));
        }
        return $currentIds;
    }

    /**
     * True pokud daný item type je plně skrytý (forever / historical bez baseline nebo s aktivním day/week).
     */
    private function isFullyDismissed(array $dismissals, string $itemType): bool
    {
        if (!isset($dismissals[$itemType])) {
            return false;
        }
        $d = $dismissals[$itemType];
        if ($d['mode'] === 'forever' || $d['mode'] === 'historical') {
            return true;
        }
        if (($d['mode'] === 'day' || $d['mode'] === 'week')
            && $d['dismissed_until'] !== null
            && strtotime($d['dismissed_until']) > time()) {
            return true;
        }
        return false;
    }

    /**
     * Uloží dismissal pro user + item_type.
     * Mode 'historical' → snapshotuje aktuální set ID daného typu.
     * Mode 'day' / 'week' → spočítá dismissed_until.
     * Mode 'forever' → dismissed_until = NULL.
     */
    public function dismissActionItem(int $supplierId, int $userId, string $itemType, string $mode): void
    {
        $validTypes = ['overdue_invoices', 'recurring_due', 'overdue_payables', 'tax_deadline', 'churn_risk'];
        $validModes = ['day', 'week', 'forever', 'historical'];
        if (!in_array($itemType, $validTypes, true)) {
            throw new \InvalidArgumentException("Invalid item_type: {$itemType}");
        }
        if (!in_array($mode, $validModes, true)) {
            throw new \InvalidArgumentException("Invalid mode: {$mode}");
        }

        $dismissedUntil = null;
        if ($mode === 'day') {
            $dismissedUntil = (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s');
        } elseif ($mode === 'week') {
            $dismissedUntil = (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
        }

        $baselineJson = null;
        if ($mode === 'historical') {
            $ids = $this->snapshotCurrentIds($supplierId, $itemType);
            $baselineJson = json_encode($ids, JSON_THROW_ON_ERROR);
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO crm_action_item_dismissals
                (supplier_id, user_id, item_type, mode, dismissed_until, baseline_ids)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                mode = VALUES(mode),
                dismissed_until = VALUES(dismissed_until),
                baseline_ids = VALUES(baseline_ids),
                created_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$supplierId, $userId, $itemType, $mode, $dismissedUntil, $baselineJson]);
    }

    /**
     * Smaže dismissal pro user + item_type (restore notification).
     */
    public function restoreActionItem(int $supplierId, int $userId, string $itemType): void
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ? AND item_type = ?"
        );
        $stmt->execute([$supplierId, $userId, $itemType]);
    }

    /**
     * Smaže VŠECHNY dismissals pro user — full restore.
     */
    public function restoreAllActionItems(int $supplierId, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM crm_action_item_dismissals WHERE supplier_id = ? AND user_id = ?"
        );
        $stmt->execute([$supplierId, $userId]);
        return $stmt->rowCount();
    }

    /**
     * Vrátí počet aktivních dismissals pro user (pro UI: zobrazit "obnovit X skrytých").
     */
    public function dismissedCount(int $supplierId, int $userId): int
    {
        // Auto-cleanup expirovaných day/week
        $this->db->pdo()->prepare(
            "DELETE FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ?
                AND mode IN ('day','week') AND dismissed_until IS NOT NULL AND dismissed_until < NOW()"
        )->execute([$supplierId, $userId]);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM crm_action_item_dismissals WHERE supplier_id = ? AND user_id = ?"
        );
        $stmt->execute([$supplierId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<int>
     */
    private function snapshotCurrentIds(int $supplierId, string $itemType): array
    {
        $pdo = $this->db->pdo();
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        switch ($itemType) {
            case 'overdue_invoices':
                $stmt = $pdo->prepare(
                    "SELECT id FROM invoices
                      WHERE supplier_id = ?
                        AND status IN ('issued','sent','reminded')
                        AND invoice_type != 'proforma'
                        AND due_date < ?"
                );
                $stmt->execute([$supplierId, $today]);
                break;
            case 'recurring_due':
                $stmt = $pdo->prepare(
                    "SELECT id FROM recurring_invoice_templates
                      WHERE supplier_id = ?
                        AND (end_date IS NULL OR end_date >= ?)
                        AND next_run_date IS NOT NULL
                        AND next_run_date <= DATE_ADD(?, INTERVAL 3 DAY)
                        AND next_run_date >= ?"
                );
                $stmt->execute([$supplierId, $today, $today, $today]);
                break;
            case 'overdue_payables':
                $stmt = $pdo->prepare(
                    "SELECT id FROM purchase_invoices
                      WHERE supplier_id = ?
                        AND status IN ('received','booked')
                        AND due_date < ?"
                );
                $stmt->execute([$supplierId, $today]);
                break;
            case 'churn_risk':
                $stmt = $pdo->prepare(
                    "WITH last_invoice AS (
                        SELECT client_id, MAX(issue_date) AS last_date
                          FROM invoices
                         WHERE supplier_id = ?
                           AND status NOT IN ('draft','cancelled')
                         GROUP BY client_id
                      )
                      SELECT li.client_id FROM last_invoice li
                      JOIN clients c ON c.id = li.client_id
                     WHERE DATEDIFF(?, li.last_date) >= 90"
                );
                $stmt->execute([$supplierId, $today]);
                break;
            case 'tax_deadline':
                return []; // date-based, žádné ID
            default:
                return [];
        }
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Cash flow forecast — predicted in/out per week dopředu.
     *
     * @return array{
     *   currency: string,
     *   weeks: list<array{week_start:string, week_end:string, in:float, out:float, net:float, running:float}>,
     *   total_in: float, total_out: float, total_net: float
     * }
     */
    public function cashFlowForecast(int $supplierId, int $weeksAhead = 4, string $currency = 'CZK'): array
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');

        // Build week buckets
        $weeks = [];
        $running = 0.0;
        $totalIn = 0.0;
        $totalOut = 0.0;

        for ($w = 0; $w < $weeksAhead; $w++) {
            $weekStart = $today->modify("+{$w} weeks")->modify('Monday this week');
            if ($w === 0) {
                // První týden začíná dneškem (ne pondělím)
                $weekStart = $today;
            }
            $weekEnd = $weekStart->modify('Sunday this week');
            if ($w === 0 && $weekEnd < $weekStart) {
                $weekEnd = $weekStart->modify('+6 days');
            }

            // In: nezaplacené vystavené faktury s due_date v tomto týdnu
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(i.total_with_vat), 0) AS amt
                   FROM invoices i
              LEFT JOIN currencies c ON c.id = i.currency_id
                  WHERE i.supplier_id = ?
                    AND i.status IN ('issued', 'sent', 'reminded')
                    AND i.invoice_type != 'proforma'
                    AND i.due_date BETWEEN ? AND ?
                    AND COALESCE(c.code, 'CZK') = ?"
            );
            $stmt->execute([$supplierId, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'), $currency]);
            $in = (float) $stmt->fetchColumn();

            // Out: nezaplacené přijaté faktury s due_date v tomto týdnu
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(pi.total_with_vat), 0) AS amt
                   FROM purchase_invoices pi
              LEFT JOIN currencies c ON c.id = pi.currency_id
                  WHERE pi.supplier_id = ?
                    AND pi.status IN ('received', 'booked')
                    AND pi.due_date BETWEEN ? AND ?
                    AND COALESCE(c.code, 'CZK') = ?"
            );
            $stmt->execute([$supplierId, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'), $currency]);
            $out = (float) $stmt->fetchColumn();

            $net = $in - $out;
            $running += $net;
            $totalIn += $in;
            $totalOut += $out;

            $weeks[] = [
                'week_start' => $weekStart->format('Y-m-d'),
                'week_end'   => $weekEnd->format('Y-m-d'),
                'in'         => round($in, 2),
                'out'        => round($out, 2),
                'net'        => round($net, 2),
                'running'    => round($running, 2),
            ];
        }

        return [
            'currency'  => $currency,
            'weeks'     => $weeks,
            'total_in'  => round($totalIn, 2),
            'total_out' => round($totalOut, 2),
            'total_net' => round($totalIn - $totalOut, 2),
        ];
    }

    /**
     * Late payment risk score per klient — kdo platí pozdě.
     *
     * Score 0-100:
     *   0 = nikdy late
     *   100 = vždy late, dlouho
     *
     * Formula: late_rate * 50 + min(50, avg_days_late) → cap 100
     *
     * @return list<array{
     *   client_id:int, company_name:string, total_paid:int, late_count:int,
     *   late_rate:float, avg_days_late:float, score:int, risk_level:string
     * }>
     */
    public function lateRisk(int $supplierId, int $limit = 10): array
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH paid_invoices AS (
                SELECT i.client_id,
                       i.due_date,
                       i.paid_at,
                       DATEDIFF(i.paid_at, i.due_date) AS days_late
                  FROM invoices i
                 WHERE i.supplier_id = ?
                   AND i.status = 'paid'
                   AND i.paid_at IS NOT NULL
                   AND i.due_date IS NOT NULL
                   AND i.invoice_type != 'proforma'
              )
              SELECT pi.client_id,
                     c.company_name,
                     COUNT(*) AS total_paid,
                     SUM(CASE WHEN pi.days_late > 0 THEN 1 ELSE 0 END) AS late_count,
                     AVG(CASE WHEN pi.days_late > 0 THEN pi.days_late ELSE NULL END) AS avg_days_late
                FROM paid_invoices pi
                JOIN clients c ON c.id = pi.client_id
            GROUP BY pi.client_id, c.company_name
              HAVING total_paid >= 2
            ORDER BY (SUM(CASE WHEN pi.days_late > 0 THEN 1 ELSE 0 END) / COUNT(*)) DESC,
                     avg_days_late DESC
              LIMIT ?"
        );
        $stmt->execute([$supplierId, $limit]);
        return array_map(function ($r) {
            $totalPaid = (int) $r['total_paid'];
            $lateCount = (int) $r['late_count'];
            $lateRate = $totalPaid > 0 ? ($lateCount / $totalPaid) : 0.0;
            $avgDaysLate = $r['avg_days_late'] !== null ? (float) $r['avg_days_late'] : 0.0;
            $score = (int) min(100, $lateRate * 50 + min(50, $avgDaysLate));
            $riskLevel = $score >= 50 ? 'high' : ($score >= 20 ? 'medium' : 'low');
            return [
                'client_id'     => (int) $r['client_id'],
                'company_name'  => (string) $r['company_name'],
                'total_paid'    => $totalPaid,
                'late_count'    => $lateCount,
                'late_rate'     => round($lateRate, 3),
                'avg_days_late' => round($avgDaysLate, 1),
                'score'         => $score,
                'risk_level'    => $riskLevel,
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Reminder effectiveness — funnel jaké % faktur platí po 1./2./3. upomínce.
     *
     * @return array{
     *   total_paid: int,
     *   no_reminder: int,        — zaplaceno bez upomínky
     *   after_first: int,        — zaplaceno po 1. upomínce
     *   after_second: int,       — po 2. (eskalovaně)
     *   after_third_plus: int,   — po 3+ (vážně problémové)
     *   never_paid: int,         — odeslané upomínky, ale dosud nezaplaceno
     *   avg_reminders_to_paid: float
     * }
     */
    public function reminderEffectiveness(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify("-{$monthsBack} months")->format('Y-m-d');
        $pdo = $this->db->pdo();

        // Count reminders per invoice (z `invoices.reminder_count` denorm sloupce)
        $stmt = $pdo->prepare(
            "SELECT i.id AS invoice_id,
                    i.status,
                    COALESCE(i.reminder_count, 0) AS reminder_count
               FROM invoices i
              WHERE i.supplier_id = ?
                AND i.status IN ('paid', 'sent', 'reminded')
                AND i.invoice_type != 'proforma'
                AND i.issue_date >= ?"
        );
        $stmt->execute([$supplierId, $start]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [
            'total_paid' => 0, 'no_reminder' => 0, 'after_first' => 0,
            'after_second' => 0, 'after_third_plus' => 0, 'never_paid' => 0,
        ];
        $reminderCountSumPaid = 0;
        foreach ($rows as $r) {
            $cnt = (int) $r['reminder_count'];
            if ($r['status'] === 'paid') {
                $result['total_paid']++;
                $reminderCountSumPaid += $cnt;
                if ($cnt === 0) $result['no_reminder']++;
                elseif ($cnt === 1) $result['after_first']++;
                elseif ($cnt === 2) $result['after_second']++;
                else $result['after_third_plus']++;
            } elseif ($cnt > 0) {
                $result['never_paid']++;
            }
        }
        $result['avg_reminders_to_paid'] = $result['total_paid'] > 0
            ? round($reminderCountSumPaid / $result['total_paid'], 2)
            : 0.0;

        return $result;
    }

    /**
     * Invoice → paid time histogram — distribuce (paid_at - issue_date) v dnech.
     *
     * Buckets: 0-7, 8-14, 15-30, 31-60, 61+
     *
     * @return array{
     *   buckets: list<array{label:string, count:int, percent:float, min:int, max:?int}>,
     *   total_invoices: int,
     *   median_days: ?int,
     *   p90_days: ?int
     * }
     */
    public function paymentTimeHistogram(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify("-{$monthsBack} months")->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATEDIFF(paid_at, issue_date) AS days
               FROM invoices
              WHERE supplier_id = ?
                AND status = 'paid'
                AND paid_at IS NOT NULL
                AND invoice_type != 'proforma'
                AND issue_date >= ?
                AND paid_at >= issue_date"
        );
        $stmt->execute([$supplierId, $start]);
        $days = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $total = count($days);
        if ($total === 0) {
            return ['buckets' => [], 'total_invoices' => 0, 'median_days' => null, 'p90_days' => null];
        }

        $buckets = [
            ['label' => '0–7 dní', 'min' => 0, 'max' => 7, 'count' => 0],
            ['label' => '8–14 dní', 'min' => 8, 'max' => 14, 'count' => 0],
            ['label' => '15–30 dní', 'min' => 15, 'max' => 30, 'count' => 0],
            ['label' => '31–60 dní', 'min' => 31, 'max' => 60, 'count' => 0],
            ['label' => '61+ dní', 'min' => 61, 'max' => null, 'count' => 0],
        ];
        foreach ($days as $d) {
            foreach ($buckets as $i => $b) {
                if ($d >= $b['min'] && ($b['max'] === null || $d <= $b['max'])) {
                    $buckets[$i]['count']++;
                    break;
                }
            }
        }
        foreach ($buckets as $i => $b) {
            $buckets[$i]['percent'] = round(($b['count'] / $total) * 100, 1);
        }

        sort($days);
        $medianIdx = (int) floor($total / 2);
        $p90Idx = (int) floor($total * 0.9);
        return [
            'buckets'        => $buckets,
            'total_invoices' => $total,
            'median_days'    => $days[$medianIdx] ?? null,
            'p90_days'       => $days[$p90Idx] ?? null,
        ];
    }
}
