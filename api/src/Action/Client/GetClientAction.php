<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $client = $this->repo->find($id);
        if ($client === null || (int) ($client['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }
        $client['projects'] = $this->repo->projectsForClient($id);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
        $stmt->execute([$id]);
        $client['invoices_count'] = (int) $stmt->fetchColumn();

        // VAT-aware obrat — plátci DPH vidí čísla bez DPH (relevantní pro DPH limit),
        // neplátci s DPH (fakturované částky odpovídají reálnému inkasu).
        $vatStmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $vatStmt->execute([$sid]);
        $rev = ((bool) $vatStmt->fetchColumn()) ? 'i.total_without_vat' : 'i.total_with_vat';

        // Obrat po měsících za posledních 24 měsíců.
        // Zahrnuje invoice + credit_note (dobropis má záporné částky, automaticky odečte).
        // Vyloučeno: koncepty (draft), zálohovky (proforma), storno (cancelled), interní cancellation.
        $stmtM = $pdo->prepare(
            "SELECT DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS month,
                    cur.code AS currency, SUM($rev) AS total
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.client_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
                AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
              GROUP BY month, cur.code
              ORDER BY month"
        );
        $stmtM->execute([$id]);
        $client['revenue_by_month'] = array_map(
            fn (array $r) => ['month' => $r['month'], 'currency' => $r['currency'], 'total' => (float) $r['total']],
            $stmtM->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Obrat po letech — stejná pravidla jako revenue_by_month: invoice + credit_note,
        // vyloučí draft/proforma/cancelled/cancellation.
        $stmtY = $pdo->prepare(
            "SELECT YEAR(COALESCE(i.tax_date, i.issue_date)) AS year,
                    cur.code AS currency, SUM($rev) AS total, COUNT(*) AS count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.client_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
              GROUP BY year, cur.code
              ORDER BY year DESC"
        );
        $stmtY->execute([$id]);
        $client['revenue_by_year'] = array_map(
            fn (array $r) => [
                'year' => (int) $r['year'],
                'currency' => $r['currency'],
                'total' => (float) $r['total'],
                'count' => (int) $r['count'],
            ],
            $stmtY->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Obrat po zakázkách — pro graf "Obrat podle zakázek" v detailu klienta.
        // Stejná pravidla jako revenue_by_month / revenue_by_year.
        // Faktury bez project_id se agregují pod label "(bez zakázky)" (project_id NULL).
        $stmtP = $pdo->prepare(
            "SELECT i.project_id, p.name AS project_name,
                    cur.code AS currency, SUM($rev) AS total, COUNT(*) AS count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
          LEFT JOIN projects p ON p.id = i.project_id
              WHERE i.client_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
              GROUP BY i.project_id, p.name, cur.code
              ORDER BY total DESC"
        );
        $stmtP->execute([$id]);
        $client['revenue_by_project'] = array_map(
            fn (array $r) => [
                'project_id'   => $r['project_id'] !== null ? (int) $r['project_id'] : null,
                'project_name' => $r['project_name'],
                'currency'     => $r['currency'],
                'total'        => (float) $r['total'],
                'count'        => (int) $r['count'],
            ],
            $stmtP->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Nezaplaceno (issued/sent + invoice/credit_note) + Po splatnosti per měna
        $stmtU = $pdo->prepare(
            "SELECT cur.code AS currency,
                    SUM(i.amount_to_pay) AS unpaid_total, COUNT(*) AS unpaid_count,
                    SUM(CASE WHEN i.due_date <= CURDATE() THEN i.amount_to_pay ELSE 0 END) AS overdue_total,
                    SUM(CASE WHEN i.due_date <= CURDATE() THEN 1 ELSE 0 END) AS overdue_count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.client_id = ?
                AND i.status IN ('issued','sent','reminded')
                AND i.invoice_type IN ('invoice','credit_note')
              GROUP BY cur.code"
        );
        $stmtU->execute([$id]);
        $client['unpaid_summary'] = array_map(
            fn (array $r) => [
                'currency'      => $r['currency'],
                'unpaid_total'  => (float) $r['unpaid_total'],
                'unpaid_count'  => (int) $r['unpaid_count'],
                'overdue_total' => (float) $r['overdue_total'],
                'overdue_count' => (int) $r['overdue_count'],
            ],
            $stmtU->fetchAll(\PDO::FETCH_ASSOC)
        );

        return Json::ok($response, $client);
    }
}
