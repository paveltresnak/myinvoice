<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agregace pro Dashboard:
 *  - KPI: letošní obrat per měna, YoY change, počet vystavených, průměrná doba úhrady
 *  - Po splatnosti: tabulka faktur s due_date < today, status issued/sent
 *  - Nezaplacené (před splatností)
 *  - Top klienti YTD
 *  - Obrat po měsících (12 měsíců současný + minulý rok)
 *
 * Storno (cancelled) a interní cancellation se z obratu vyřazují.
 */
final class SummaryAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $prevYear = $year - 1;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $isVatPayer = $this->fetchIsVatPayer($pdo, $sid);

        return Json::ok($response, [
            'kpi'                    => $this->kpi($pdo, $year, $prevYear, $sid, $isVatPayer),
            'overdue'                => $this->overdue($pdo, $sid),
            'unpaid_upcoming'        => $this->unpaidUpcoming($pdo, $sid),
            'top_clients_ytd'        => $this->topClients($pdo, $year, $sid, $isVatPayer),
            'top_clients_prev_year'  => $this->topClients($pdo, $prevYear, $sid, $isVatPayer),
            'top_clients_12m'        => $this->topClientsRolling12m($pdo, $sid, $isVatPayer),
            'revenue_by_month'       => $this->revenueByMonth($pdo, $sid, $isVatPayer),
            'revenue_by_year'        => $this->revenueByYear($pdo, $sid, $isVatPayer),
            'rolling_12m'            => $this->rolling12mRevenue($pdo, $sid, $isVatPayer),
            'cashflow_ytd'           => $this->cashflowYtd($pdo, $year, $prevYear, $sid),
            'payment_days_histogram' => $this->paymentDaysHistogram($pdo, $sid),
            'vat_breakdown_12m'      => $isVatPayer ? $this->vatBreakdown12m($pdo, $sid) : [],
            'cashflow_forecast'      => $this->cashflowForecast($pdo, $sid),
            'due_buckets'            => $this->dueBuckets($pdo, $sid),
            'aging_report'           => $this->agingReport($pdo, $sid),
            'revenue_forecast'       => $this->revenueForecast($pdo, $year, $prevYear, $sid, $isVatPayer),
            'invoice_size_histogram' => $this->invoiceSizeHistogram($pdo, $sid, $isVatPayer),
            'revenue_last_30d'       => $this->revenueLast30d($pdo, $sid, $isVatPayer),
            'active_recurring_count' => $this->activeRecurringCount($pdo, $sid),
            'active_clients_count'   => $this->activeClientsCount($pdo, $sid),
            'pending_approvals'      => $this->pendingApprovals($pdo, $sid),
            'today'                  => $today->format('Y-m-d'),
            'year'                   => $year,
            'prev_year'              => $prevYear,
            'is_vat_payer'           => $isVatPayer,
        ]);
    }

    /** Počet aktivních (neaarchivovaných) klientů v rámci aktuálního dodavatele. */
    private function activeClientsCount(\PDO $pdo, int $sid): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND archived_at IS NULL');
        $stmt->execute([$sid]);
        return (int) $stmt->fetchColumn();
    }

    /** Počet aktivních pravidelných fakturací (status='active'). */
    private function activeRecurringCount(\PDO $pdo, int $sid): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM recurring_invoice_templates WHERE supplier_id = ? AND status = 'active'");
        $stmt->execute([$sid]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Obrat za posledních 30 dní per měna — live indikátor aktuální fakturace.
     * @return list<array{currency: string, total: float, invoice_count: int}>
     */
    private function revenueLast30d(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT cur.code AS currency, SUM($rev) AS total, COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'      => (string) $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Obrat po rocích — všechny roky, ve kterých existují fakturované doklady (invoice + credit_note),
     * VAT-aware sloupec. Pro tabulkové zobrazení v Grafech.
     *
     * @return list<array{year: int, currency: string, total: float, invoice_count: int}>
     */
    private function revenueByYear(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT YEAR(COALESCE(i.tax_date, i.issue_date)) AS year,
                       cur.code AS currency,
                       SUM($rev) AS total,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY year, cur.code
                 ORDER BY year DESC, total DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'year'          => (int) $r['year'],
            'currency'      => (string) $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Zjistí, zda je aktuální dodavatel plátce DPH — určuje, který sloupec použijeme
     * pro agregaci obratu (`total_without_vat` pro plátce, `total_with_vat` pro neplátce).
     */
    private function fetchIsVatPayer(\PDO $pdo, int $sid): bool
    {
        $stmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return (bool) $stmt->fetchColumn();
    }

    /** SQL fragment vybírající správný sloupec obratu dle plátcovství DPH. */
    private function revenueCol(bool $isVatPayer): string
    {
        return $isVatPayer ? 'i.total_without_vat' : 'i.total_with_vat';
    }

    /**
     * Schvalování výkazu zákazníkem — count requested + overdue (>5 dní).
     * Klik na tile → /admin/approvals.
     * @return array{requested: int, overdue: int}
     */
    private function pendingApprovals(\PDO $pdo, int $sid): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN approval_status = 'requested' THEN 1 ELSE 0 END) AS requested,
                SUM(CASE WHEN approval_status = 'requested'
                          AND COALESCE(approval_reminder_at, approval_requested_at)
                              <= DATE_SUB(NOW(), INTERVAL 5 DAY) THEN 1 ELSE 0 END) AS overdue
              FROM invoices
             WHERE supplier_id = ?"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['requested' => 0, 'overdue' => 0];
        return [
            'requested' => (int) ($row['requested'] ?? 0),
            'overdue'   => (int) ($row['overdue'] ?? 0),
        ];
    }

    private function kpi(\PDO $pdo, int $year, int $prevYear, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        // Obrat per měna pro YTD (letošní vs. minulý rok)
        // Záměrně počítáme i NEZAPLACENÉ faktury, pokud jsou vystavené (status: issued / sent / paid).
        // Dobropisy (credit_note) ZAHRNUJEME — mají záporné total_with_vat (viz CancelInvoiceAction),
        // takže se SUMou automaticky odečtou od obratu. Koncepty (draft) a zálohovky (proforma) nezapočítáváme.
        //
        // change_pct: porovnává this_year (YTD) s prev_year_ytd — tj. minulý rok jen do stejné kalendářní
        // pozice (DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) — fair YoY pro nedokončený aktuální rok.
        // prev_year zůstává jako celoroční total pro kontext (zobrazení v UI / fallback grafy).
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS this_year,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS prev_year,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                  AND COALESCE(i.tax_date, i.issue_date) <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $rev ELSE 0 END) AS prev_year_ytd,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN 1 ELSE 0 END) AS this_year_invoice_count,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN 1 ELSE 0 END) AS prev_year_invoice_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.client_id END) AS this_year_client_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.client_id END) AS prev_year_client_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.project_id END) AS this_year_project_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.project_id END) AS prev_year_project_count
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN (?, ?)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $year, $prevYear, $prevYear,
            $year, $prevYear,
            $year, $prevYear,
            $year, $prevYear,
            $sid, $year, $prevYear,
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $perCurrency = [];
        foreach ($rows as $r) {
            $thisYear = (float) $r['this_year'];
            $prevYearTotal = (float) $r['prev_year'];
            $prevYearYtd = (float) $r['prev_year_ytd'];
            $changePct = null;
            if ($prevYearYtd > 0) {
                $changePct = round((($thisYear - $prevYearYtd) / $prevYearYtd) * 100, 1);
            }
            $perCurrency[(string) $r['currency']] = [
                'currency'                 => (string) $r['currency'],
                'this_year'                => round($thisYear, 2),
                'prev_year'                => round($prevYearTotal, 2),
                'prev_year_ytd'            => round($prevYearYtd, 2),
                'change_pct'               => $changePct,
                'this_year_invoice_count'  => (int) $r['this_year_invoice_count'],
                'prev_year_invoice_count'  => (int) $r['prev_year_invoice_count'],
                'this_year_client_count'   => (int) $r['this_year_client_count'],
                'prev_year_client_count'   => (int) $r['prev_year_client_count'],
                'this_year_project_count'  => (int) $r['this_year_project_count'],
                'prev_year_project_count'  => (int) $r['prev_year_project_count'],
            ];
        }

        // Počet vystavených YTD — proformy se nezapočítávají (nejde o finální daňový doklad).
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type IN ('invoice', 'credit_note')"
        );
        $stmt->execute([$sid, $year]);
        $issuedCount = (int) $stmt->fetchColumn();

        // Po splatnosti — počet a celkem k úhradě
        $stmt = $pdo->prepare(
            "SELECT cur.code AS currency, COUNT(*) AS cnt, SUM(i.amount_to_pay) AS total
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded') AND i.due_date <= CURDATE()
                AND i.invoice_type IN ('invoice','credit_note')
              GROUP BY cur.code"
        );
        $stmt->execute([$sid]);
        $overdue = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $overduePerCurrency = array_map(fn (array $r) => [
            'currency' => $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => round((float) $r['total'], 2),
        ], $overdue);
        $overdueTotalCount = array_sum(array_column($overduePerCurrency, 'count'));

        // Průměrná doba úhrady (paid_at - issue_date) ve dnech, pro letošní zaplacené
        $stmt = $pdo->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) FROM invoices
              WHERE supplier_id = ? AND status = 'paid' AND paid_at IS NOT NULL
                AND YEAR(COALESCE(tax_date, issue_date)) = ?"
        );
        $stmt->execute([$sid, $year]);
        $avgPaymentDays = $stmt->fetchColumn();
        $avgPaymentDays = $avgPaymentDays !== null && $avgPaymentDays !== false
            ? round((float) $avgPaymentDays, 1)
            : null;

        // Stav faktur YTD (počet) — pro fallback chart když není prev year
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
               FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND invoice_type = 'invoice'
              GROUP BY status"
        );
        $stmt->execute([$sid, $year]);
        $statusCounts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $statusCounts[$r['status']] = (int) $r['cnt'];
        }

        return [
            'per_currency'        => array_values($perCurrency),
            'issued_count_ytd'    => $issuedCount,
            'overdue_count'       => $overdueTotalCount,
            'overdue_per_currency'=> $overduePerCurrency,
            'avg_payment_days'    => $avgPaymentDays,
            'status_counts_ytd'   => $statusCounts,
        ];
    }

    private function overdue(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name,
                       DATEDIFF(CURDATE(), i.due_date) AS days_overdue
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date <= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function unpaidUpcoming(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date >= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function topClients(\PDO $pdo, int $year, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT c.id, c.company_name, cur.code AS currency,
                       SUM($rev) AS total,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY c.id, c.company_name, cur.code
                 ORDER BY total DESC
                 LIMIT 12";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => [
            'client_id'     => (int) $r['id'],
            'company_name'  => $r['company_name'],
            'currency'      => $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $rows);
    }

    /**
     * Obrat za posledních 12 měsíců (rolling window končící aktuálním měsícem) + porovnávací řada
     * pro stejných 12 měsíců o rok dříve (–1 rok), per měna.
     *
     * Output: [
     *   { currency: 'CZK',
     *     months:    [ { ym: 'YYYY-MM', total: 0.0 }, ... 12 entries ascending ],
     *     prev_year: [ { ym: 'YYYY-MM', total: 0.0 }, ... 12 entries ascending, –12 měsíců ] },
     *   ...
     * ]
     */
    private function revenueByMonth(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        // Okno aktuálních 12 měsíců + 12 měsíců o rok dříve = celkem 24 měsíců dat.
        // Začátek = (dnes − 23 měsíců, 1. den měsíce).
        $sql = "SELECT cur.code AS currency,
                       DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS ym,
                       SUM($rev) AS total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 23 MONTH), '%Y-%m-01')
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code, ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Sloty: aktuální 12 měsíců (months) + 12 měsíců o rok dříve (prev_year).
        $monthsSlots = [];
        $prevSlots = [];
        $cursor = new \DateTimeImmutable(date('Y-m-01'));
        $cursorThis = $cursor->modify('-11 months');
        $cursorPrev = $cursor->modify('-23 months');
        for ($i = 0; $i < 12; $i++) {
            $monthsSlots[$cursorThis->format('Y-m')] = 0.0;
            $prevSlots[$cursorPrev->format('Y-m')]   = 0.0;
            $cursorThis = $cursorThis->modify('+1 month');
            $cursorPrev = $cursorPrev->modify('+1 month');
        }

        // Skupina per měna — totaly přiřaď do správného slotu (current vs. prev) dle YYYY-MM klíče.
        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ym = (string) $r['ym'];
            $total = round((float) $r['total'], 2);
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = ['months' => $monthsSlots, 'prev_year' => $prevSlots];
            }
            if (array_key_exists($ym, $perCurrency[$cur]['months'])) {
                $perCurrency[$cur]['months'][$ym] = $total;
            } elseif (array_key_exists($ym, $perCurrency[$cur]['prev_year'])) {
                $perCurrency[$cur]['prev_year'][$ym] = $total;
            }
        }

        $toList = static fn (array $slots): array => array_map(
            static fn ($ym, $t) => ['ym' => $ym, 'total' => $t],
            array_keys($slots),
            $slots
        );

        $out = [];
        foreach ($perCurrency as $cur => $data) {
            $out[] = [
                'currency'  => $cur,
                'months'    => $toList($data['months']),
                'prev_year' => $toList($data['prev_year']),
            ];
        }
        return $out;
    }

    /**
     * Top klienti za posledních 12 měsíců (rolling window) — robustní vůči začátku roku,
     * kdy by YTD bylo téměř prázdné.
     */
    private function topClientsRolling12m(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT c.id, c.company_name, cur.code AS currency,
                       SUM($rev) AS total,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY c.id, c.company_name, cur.code
                 ORDER BY total DESC
                 LIMIT 12";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'client_id'     => (int) $r['id'],
            'company_name'  => $r['company_name'],
            'currency'      => $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Plovoucí 12měsíční obrat (rolling) — součet posledních 12 ukončených měsíců + aktuální měsíc.
     * Relevantní pro sledování limitu DPH (2 mil. CZK / 12 měsíců). Per měna.
     *
     * @return list<array{currency: string, total: float, prev_period_total: float}>
     */
    private function rolling12mRevenue(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $rev ELSE 0 END) AS total_12m,
                       SUM(CASE WHEN COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                                  AND COALESCE(i.tax_date, i.issue_date) <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $rev ELSE 0 END) AS total_prev_12m
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'          => (string) $r['currency'],
            'total'             => round((float) $r['total_12m'], 2),
            'prev_period_total' => round((float) $r['total_prev_12m'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Kumulativní cash-flow (skutečné inkasované platby) per měsíc — letošek + minulý rok.
     * Bere `paid_at` (kdy byla faktura zaplacena), ne issue_date. Per měna.
     *
     * @return list<array{currency: string, months: list<array{ym: string, total: float}>, prev_year: list<array{ym: string, total: float}>}>
     */
    private function cashflowYtd(\PDO $pdo, int $year, int $prevYear, int $sid): array
    {
        // Cash-flow je vždy v měnové hodnotě s DPH (klient zaplatil reálnou částku).
        // Filtr i.status = 'paid' a paid_at NOT NULL.
        $sql = "SELECT cur.code AS currency,
                       DATE_FORMAT(i.paid_at, '%Y-%m') AS ym,
                       SUM(i.total_with_vat) AS total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status = 'paid'
                   AND i.paid_at IS NOT NULL
                   AND YEAR(i.paid_at) IN (?, ?)
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code, ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year, $prevYear]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $thisSlots = [];
        $prevSlots = [];
        for ($m = 1; $m <= 12; $m++) {
            $thisSlots[sprintf('%04d-%02d', $year, $m)] = 0.0;
            $prevSlots[sprintf('%04d-%02d', $prevYear, $m)] = 0.0;
        }
        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ym = (string) $r['ym'];
            $total = round((float) $r['total'], 2);
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = ['months' => $thisSlots, 'prev_year' => $prevSlots];
            }
            if (array_key_exists($ym, $perCurrency[$cur]['months'])) {
                $perCurrency[$cur]['months'][$ym] = $total;
            } elseif (array_key_exists($ym, $perCurrency[$cur]['prev_year'])) {
                $perCurrency[$cur]['prev_year'][$ym] = $total;
            }
        }
        $toList = static fn (array $slots): array => array_map(
            static fn ($ym, $t) => ['ym' => $ym, 'total' => $t],
            array_keys($slots),
            $slots
        );
        $out = [];
        foreach ($perCurrency as $cur => $data) {
            $out[] = [
                'currency'  => $cur,
                'months'    => $toList($data['months']),
                'prev_year' => $toList($data['prev_year']),
            ];
        }
        return $out;
    }

    /**
     * Histogram doby úhrady — kolik faktur bylo zaplaceno v jakém časovém okně po vystavení.
     * Okno = posledních 12 měsíců (rolling) ohledně paid_at.
     *
     * Buckets: 0-7 dní (zaplaceno do týdne), 8-14, 15-30, 30+.
     * Záporné dny (paid_at < issue_date — zaplaceno předem) sjednoceně do bucketu "0-7".
     *
     * @return array{buckets: list<array{key: string, label: string, count: int}>, total: int, avg_days: float|null}
     */
    private function paymentDaysHistogram(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT DATEDIFF(paid_at, issue_date) AS days
                  FROM invoices
                 WHERE supplier_id = ?
                   AND status = 'paid'
                   AND paid_at IS NOT NULL
                   AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND invoice_type IN ('invoice', 'credit_note')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $days = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $buckets = [
            '0-7'   => ['key' => '0-7',   'label' => '0–7 dní',  'count' => 0],
            '8-14'  => ['key' => '8-14',  'label' => '8–14 dní', 'count' => 0],
            '15-30' => ['key' => '15-30', 'label' => '15–30 dní', 'count' => 0],
            '30+'   => ['key' => '30+',   'label' => '30+ dní',   'count' => 0],
        ];
        $sum = 0;
        foreach ($days as $d) {
            $d = (int) $d;
            $sum += max(0, $d);
            if ($d <= 7)       $buckets['0-7']['count']++;
            elseif ($d <= 14)  $buckets['8-14']['count']++;
            elseif ($d <= 30)  $buckets['15-30']['count']++;
            else               $buckets['30+']['count']++;
        }
        $total = count($days);
        $avg = $total > 0 ? round($sum / $total, 1) : null;

        return [
            'buckets'  => array_values($buckets),
            'total'    => $total,
            'avg_days' => $avg,
        ];
    }

    /**
     * Rozpad obratu (bez DPH) podle sazby DPH — pro plátce DPH posledních 12 měsíců.
     * Bere `invoice_items.vat_rate_snapshot` jako kotvu. Reverse-charge řádky mají rate=0
     * a vykazují se odděleně přes invoice.reverse_charge.
     *
     * @return list<array{label: string, base: float, currency: string}>
     */
    private function vatBreakdown12m(\PDO $pdo, int $sid): array
    {
        // RC řádky se vyznačují tím, že celá faktura má `reverse_charge = 1`. Identifikujeme je
        // odděleně, aby uživatel rozlišil "skutečné 0 %" (osvobozeno) od "0 % RC".
        $sql = "SELECT cur.code AS currency,
                       CASE WHEN i.reverse_charge = 1 THEN 'RC' ELSE CAST(ii.vat_rate_snapshot AS CHAR) END AS rate_label,
                       SUM(ii.total_without_vat) AS base
                  FROM invoice_items ii
                  JOIN invoices i ON i.id = ii.invoice_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY cur.code, rate_label
                 ORDER BY cur.code, base DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            $label = $r['rate_label'] === 'RC' ? 'RC (reverse charge)' : (rtrim(rtrim((string) $r['rate_label'], '0'), '.')) . ' %';
            return [
                'label'    => $label,
                'base'     => round((float) $r['base'], 2),
                'currency' => (string) $r['currency'],
            ];
        }, $rows);
    }

    /**
     * Kumulativní cash-flow forecast — kolik se očekává inkasovat v příštích 30/60/90 dnech
     * z neuhrazených faktur (status issued/sent/reminded, due_date v daném okně). Per měna.
     *
     * @return list<array{currency: string, in_30: float, in_60: float, in_90: float, count_30: int, count_60: int, count_90: int}>
     */
    private function cashflowForecast(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN i.amount_to_pay ELSE 0 END) AS in_30,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN i.amount_to_pay ELSE 0 END) AS in_60,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN i.amount_to_pay ELSE 0 END) AS in_90,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS count_30,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS count_60,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS count_90
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.invoice_type IN ('invoice','credit_note')
                   AND i.due_date >= CURDATE()
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency' => (string) $r['currency'],
            'in_30'    => round((float) $r['in_30'], 2),
            'in_60'    => round((float) $r['in_60'], 2),
            'in_90'    => round((float) $r['in_90'], 2),
            'count_30' => (int) $r['count_30'],
            'count_60' => (int) $r['count_60'],
            'count_90' => (int) $r['count_90'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Splatnost bucket — kolik faktur je splatných **dnes / tento týden / tento měsíc**.
     * Týden = Po–Ne. Měsíc = do LAST_DAY. Bucket je inkluzivní (today včetně).
     *
     * Buckets jsou **kumulativní** — week zahrnuje today; month zahrnuje week.
     *
     * @return list<array{currency: string, today_count: int, today_total: float, week_count: int, week_total: float, month_count: int, month_total: float}>
     */
    private function dueBuckets(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN i.due_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
                       SUM(CASE WHEN i.due_date = CURDATE() THEN i.amount_to_pay ELSE 0 END) AS today_total,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE())) DAY) THEN 1 ELSE 0 END) AS week_count,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE())) DAY) THEN i.amount_to_pay ELSE 0 END) AS week_total,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN 1 ELSE 0 END) AS month_count,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN i.amount_to_pay ELSE 0 END) AS month_total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.invoice_type IN ('invoice','credit_note')
                   AND i.due_date >= CURDATE()
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'     => (string) $r['currency'],
            'today_count'  => (int) $r['today_count'],
            'today_total'  => round((float) $r['today_total'], 2),
            'week_count'   => (int) $r['week_count'],
            'week_total'   => round((float) $r['week_total'], 2),
            'month_count'  => (int) $r['month_count'],
            'month_total'  => round((float) $r['month_total'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Aging report — stáří pohledávek (neuhrazených faktur) podle počtu dní po splatnosti.
     * Bucket 'current' = ještě před splatností. Klasický finanční report.
     *
     * @return list<array{currency: string, current: float, b1_30: float, b31_60: float, b61_90: float, b90_plus: float, current_n: int, b1_30_n: int, b31_60_n: int, b61_90_n: int, b90_plus_n: int}>
     */
    private function agingReport(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN i.due_date >= CURDATE() THEN i.amount_to_pay ELSE 0 END) AS current_amt,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.amount_to_pay ELSE 0 END) AS b1_30,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.amount_to_pay ELSE 0 END) AS b31_60,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.amount_to_pay ELSE 0 END) AS b61_90,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.amount_to_pay ELSE 0 END) AS b90_plus,
                       SUM(CASE WHEN i.due_date >= CURDATE() THEN 1 ELSE 0 END) AS current_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN 1 ELSE 0 END) AS b1_30_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS b31_60_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS b61_90_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) > 90 THEN 1 ELSE 0 END) AS b90_plus_n
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.invoice_type IN ('invoice','credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'  => (string) $r['currency'],
            'current'   => round((float) $r['current_amt'], 2),
            'b1_30'     => round((float) $r['b1_30'], 2),
            'b31_60'    => round((float) $r['b31_60'], 2),
            'b61_90'    => round((float) $r['b61_90'], 2),
            'b90_plus'  => round((float) $r['b90_plus'], 2),
            'current_n' => (int) $r['current_n'],
            'b1_30_n'   => (int) $r['b1_30_n'],
            'b31_60_n'  => (int) $r['b31_60_n'],
            'b61_90_n'  => (int) $r['b61_90_n'],
            'b90_plus_n'=> (int) $r['b90_plus_n'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Forecast ročního obratu — growth-adjusted seasonality.
     *
     * Postup:
     *  1. YTD letošek (Jan 1 – dnes)
     *  2. YTD loňsko (Jan 1 – stejné datum minulého roku) — pro vypočet growth ratio
     *  3. Loňsko od dneška do konce roku (sezonalita budoucích měsíců)
     *  4. growth_ratio = YTD_this / YTD_prev (capnut na rozumný rozsah, default 1.0 pokud loni nula)
     *  5. forecast = YTD_this + (prev_remainder × growth_ratio)
     *
     * Tj. pokud rosteš +10 % YoY, predikujeme i zbytek roku o +10 % nad loni.
     *
     * @return list<array{currency: string, ytd: float, prev_year_ytd: float, prev_year_remainder: float, growth_ratio: float, forecast: float, prev_year_full: float}>
     */
    private function revenueForecast(\PDO $pdo, int $year, int $prevYear, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS ytd,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                  AND COALESCE(i.tax_date, i.issue_date) <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $rev ELSE 0 END) AS prev_year_ytd,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                  AND COALESCE(i.tax_date, i.issue_date) > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $rev ELSE 0 END) AS prev_year_remainder,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS prev_year_full
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN (?, ?)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$year, $prevYear, $prevYear, $prevYear, $sid, $year, $prevYear]);
        return array_map(static function (array $r): array {
            $ytd = round((float) $r['ytd'], 2);
            $ytdPrev = round((float) $r['prev_year_ytd'], 2);
            $rem = round((float) $r['prev_year_remainder'], 2);
            // Růstový poměr — pokud loňské YTD je 0 nebo neznámé, fallback na 1.0 (= prostá sezonalita).
            // Capnuto na [0.3, 3.0] — extrémní YoY (např. začátek byznysu) by jinak vytvořilo nerealistickou projekci.
            $growth = ($ytdPrev > 0) ? max(0.3, min(3.0, $ytd / $ytdPrev)) : 1.0;
            $forecast = round($ytd + ($rem * $growth), 2);
            return [
                'currency'            => (string) $r['currency'],
                'ytd'                 => $ytd,
                'prev_year_ytd'       => $ytdPrev,
                'prev_year_remainder' => $rem,
                'growth_ratio'        => round($growth, 3),
                'forecast'            => $forecast,
                'prev_year_full'      => round((float) $r['prev_year_full'], 2),
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Histogram velikosti faktur — distribuce za posledních 12 měsíců.
     * Buckets jsou pevné v primární měně (CZK): 0-5k / 5-25k / 25-100k / 100k+.
     * Pro non-CZK fakturu se použije `total_with_vat` převedený přes uložený `exchange_rate`
     * na CZK-ekvivalent.
     *
     * @return array{buckets: list<array{key: string, label: string, count: int, total_czk: float}>, total: int}
     */
    private function invoiceSizeHistogram(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $isVatPayer ? 'total_without_vat' : 'total_with_vat';
        // Pro non-CZK fakturu = total * COALESCE(exchange_rate, 1).
        $sql = "SELECT $rev * COALESCE(exchange_rate, 1) AS size_czk
                  FROM invoices
                 WHERE supplier_id = ?
                   AND status IN ('issued', 'sent', 'reminded', 'paid')
                   AND invoice_type IN ('invoice', 'credit_note')
                   AND COALESCE(tax_date, issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $sizes = array_map(static fn ($v) => (float) $v, $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $buckets = [
            '0-5'    => ['key' => '0-5',   'label' => '0–5 k', 'count' => 0, 'total_czk' => 0.0],
            '5-25'   => ['key' => '5-25',  'label' => '5–25 k', 'count' => 0, 'total_czk' => 0.0],
            '25-100' => ['key' => '25-100','label' => '25–100 k', 'count' => 0, 'total_czk' => 0.0],
            '100+'   => ['key' => '100+',  'label' => '100 k+', 'count' => 0, 'total_czk' => 0.0],
        ];
        foreach ($sizes as $s) {
            $abs = abs($s);
            if      ($abs <  5000)   $key = '0-5';
            elseif  ($abs <  25000)  $key = '5-25';
            elseif  ($abs <  100000) $key = '25-100';
            else                     $key = '100+';
            $buckets[$key]['count']++;
            $buckets[$key]['total_czk'] += $s;
        }
        foreach ($buckets as &$b) { $b['total_czk'] = round($b['total_czk'], 2); }
        unset($b);

        return [
            'buckets' => array_values($buckets),
            'total'   => count($sizes),
        ];
    }

    private function castListItem(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'varsymbol'           => $r['varsymbol'],
            'invoice_type'        => $r['invoice_type'],
            'client_id'           => (int) $r['client_id'],
            'client_company_name' => $r['client_company_name'],
            'currency'            => $r['currency'],
            'issue_date'          => $r['issue_date'],
            'due_date'            => $r['due_date'],
            'amount_to_pay'       => (float) $r['amount_to_pay'],
            'status'              => $r['status'],
            'days_overdue'        => isset($r['days_overdue']) ? (int) $r['days_overdue'] : null,
        ];
    }
}
