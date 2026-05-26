<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\PeriodicityCalculator;
use PDO;

/**
 * CRUD pro recurring_invoice_templates + items.
 *
 * Šablona je strukturálně blízká faktuře (client, project, currency, items, ...),
 * ale má navíc periodicitu a chování cronu (auto_issue, auto_send_email).
 *
 * Listing pro cron je v findDue() — vrátí jen aktivní šablony, kde
 * next_run_date <= today A supplier.auto_generate_recurring=1.
 */
final class RecurringTemplateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*,
                    c.company_name AS client_company_name,
                    c.main_email AS client_main_email,
                    c.language AS client_language,
                    p.name AS project_name,
                    cur.code AS currency, cur.symbol AS currency_symbol
               FROM recurring_invoice_templates t
               JOIN clients c ON c.id = t.client_id
          LEFT JOIN projects p ON p.id = t.project_id
               JOIN currencies cur ON cur.id = t.currency_id
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->cast($row);
        $row['items'] = $this->itemsFor($id);
        return $row;
    }

    public function itemsFor(int $templateId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.template_id, i.description, i.quantity, i.unit,
                    i.unit_price_without_vat, i.vat_rate_id, i.order_index,
                    vr.code AS vat_code, vr.rate_percent AS vat_rate_percent
               FROM recurring_invoice_template_items i
               JOIN vat_rates vr ON vr.id = i.vat_rate_id
              WHERE i.template_id = ?
              ORDER BY i.order_index, i.id'
        );
        $stmt->execute([$templateId]);
        return array_map([$this, 'castItem'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array{
     *   supplier_id?: int|null,
     *   client_id?: int|null,
     *   status?: string|null,
     * } $filters
     */
    /**
     * @param int $page  1-based, ignored when $perPage<=0
     * @param int $perPage  0 = bez paginace (returns vše, BC)
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['supplier_id'])) {
            $where[] = 't.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 't.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }

        // Status counts — bez status filtru, ale se zbylými filtry (supplier + client).
        // Counts pro tab badges; mají reflektovat všechny statusy pro daného supplier/client scope.
        $whereForCounts = implode(' AND ', $where);
        $stmtCounts = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN t.status = 'active'  THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN t.status = 'paused'  THEN 1 ELSE 0 END) AS paused,
                SUM(CASE WHEN t.status = 'expired' THEN 1 ELSE 0 END) AS expired,
                COUNT(*) AS all_templates
             FROM recurring_invoice_templates t
            WHERE $whereForCounts"
        );
        $stmtCounts->execute($params);
        $statusCounts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['active' => 0, 'paused' => 0, 'expired' => 0, 'all_templates' => 0];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = (string) $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        // Total pro aktuální filter
        $stmtTotal = $this->db->pdo()->prepare("SELECT COUNT(*) FROM recurring_invoice_templates t WHERE $whereSql");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $limitSql = $perPage > 0 ? ' LIMIT ? OFFSET ?' : '';

        $sql = "SELECT t.id, t.supplier_id, t.client_id, t.project_id, t.name,
                       t.frequency, t.day_of_month, t.end_of_month,
                       t.anchor_date, t.end_date, t.next_run_date, t.last_run_date,
                       t.invoice_type, t.currency_id, t.language, t.payment_method,
                       t.payment_due_days, t.draft_open_mode, t.reminder_days_before,
                       t.auto_issue, t.auto_send_email, t.status,
                       t.created_at, t.updated_at,
                       c.company_name AS client_company_name,
                       p.name AS project_name,
                       cur.code AS currency,
                       (SELECT COUNT(*) FROM invoices iv WHERE iv.recurring_template_id = t.id) AS invoices_generated_count
                  FROM recurring_invoice_templates t
                  JOIN clients c ON c.id = t.client_id
             LEFT JOIN projects p ON p.id = t.project_id
                  JOIN currencies cur ON cur.id = t.currency_id
                 WHERE $whereSql
                 ORDER BY t.status = 'active' DESC, t.next_run_date ASC{$limitSql}";

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) $stmt->bindValue($idx++, $v);
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map([$this, 'cast'], $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $perPage > 0 ? $page : 1,
                'per_page' => $perPage > 0 ? $perPage : $total,
                'pages'    => $perPage > 0 ? (int) ceil($total / max(1, $perPage)) : 1,
                'status_counts' => [
                    'all'     => (int) $statusCounts['all_templates'],
                    'active'  => (int) $statusCounts['active'],
                    'paused'  => (int) $statusCounts['paused'],
                    'expired' => (int) $statusCounts['expired'],
                ],
            ],
        ];
    }

    /**
     * Načte šablony, u kterých má dnes cron něco udělat — jsou aktivní a jejich
     * supplier má zapnutý kill-switch auto_generate_recurring, a navíc buď:
     *   - next_run_date <= today (čas vystavit / legacy generovat), NEBO
     *   - draft_open_mode='period_start' A už začalo fakturované období
     *     (1. den měsíce next_run_date <= today) → čas otevřít koncept.
     *
     * Cron pak dle draft_open_mode a datumů rozhodne open vs. issue.
     */
    public function findDue(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT t.id
               FROM recurring_invoice_templates t
               JOIN supplier s ON s.id = t.supplier_id
              WHERE t.status = 'active'
                AND (t.end_date IS NULL OR t.next_run_date <= t.end_date)
                AND s.auto_generate_recurring = 1
                AND (
                      t.next_run_date <= CURDATE()
                   OR (t.draft_open_mode = 'period_start'
                       AND DATE_FORMAT(t.next_run_date, '%Y-%m-01') <= CURDATE())
                )
              ORDER BY t.next_run_date ASC, t.id ASC"
        );
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $out = [];
        foreach ($ids as $id) {
            $tpl = $this->find($id);
            if ($tpl !== null) $out[] = $tpl;
        }
        return $out;
    }

    /**
     * Faktura vygenerovaná z této šablony pro dané období (klíč = issue_date,
     * který se rovná plánovanému next_run_date období). Slouží k idempotenci
     * openDraft() a k nalezení otevřeného konceptu v issuePeriod().
     *
     * @return array{id:int, status:string, varsymbol:?string}|null
     */
    public function findPeriodInvoice(int $templateId, string $issueDate): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, varsymbol
               FROM invoices
              WHERE recurring_template_id = ? AND issue_date = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$templateId, $issueDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'id'        => (int) $row['id'],
            'status'    => (string) $row['status'],
            'varsymbol' => $row['varsymbol'] !== null ? (string) $row['varsymbol'] : null,
        ];
    }

    /**
     * Šablony s otevřeným konceptem, kterým se blíží vystavení (period_start),
     * a kterým ještě nebyl pro toto období odeslán reminder.
     *
     * Okno: 1 ≤ (next_run_date − dnes) ≤ reminder_days_before, reminder_days_before > 0.
     */
    public function findReminderDue(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT t.id
               FROM recurring_invoice_templates t
               JOIN supplier s ON s.id = t.supplier_id
              WHERE t.status = 'active'
                AND t.draft_open_mode = 'period_start'
                AND t.reminder_days_before > 0
                AND s.auto_generate_recurring = 1
                AND DATEDIFF(t.next_run_date, CURDATE()) BETWEEN 1 AND t.reminder_days_before
                AND (t.last_reminder_date IS NULL OR t.last_reminder_date <> t.next_run_date)
              ORDER BY t.next_run_date ASC, t.id ASC"
        );
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $out = [];
        foreach ($ids as $id) {
            $tpl = $this->find($id);
            if ($tpl !== null) $out[] = $tpl;
        }
        return $out;
    }

    /** Označí, že reminder pro období (= next_run_date) byl odeslán. */
    public function markReminderSent(int $id, string $periodDate): void
    {
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates SET last_reminder_date = ? WHERE id = ?'
        )->execute([$periodDate, $id]);
    }

    public function create(array $data, int $userId): int
    {
        $pdo = $this->db->pdo();

        $sql = 'INSERT INTO recurring_invoice_templates
            (supplier_id, client_id, project_id, name,
             frequency, day_of_month, end_of_month, anchor_date, end_date, next_run_date,
             invoice_type, currency_id, language, payment_method, reverse_charge,
             payment_due_days, tax_date_mode, draft_open_mode, reminder_days_before,
             note_above_items, note_below_items,
             increment_month_in_descriptions, auto_issue, auto_send_email, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            (int) $data['supplier_id'],
            (int) $data['client_id'],
            !empty($data['project_id']) ? (int) $data['project_id'] : null,
            (string) $data['name'],
            (string) $data['frequency'],
            !empty($data['end_of_month']) ? null : (isset($data['day_of_month']) && $data['day_of_month'] !== null ? (int) $data['day_of_month'] : null),
            !empty($data['end_of_month']) ? 1 : 0,
            (string) $data['anchor_date'],
            !empty($data['end_date']) ? (string) $data['end_date'] : null,
            (string) ($data['next_run_date'] ?? $data['anchor_date']),
            (string) ($data['invoice_type'] ?? 'invoice'),
            (int) $data['currency_id'],
            (string) ($data['language'] ?? 'cs'),
            (string) ($data['payment_method'] ?? 'bank_transfer'),
            !empty($data['reverse_charge']) ? 1 : 0,
            (int) ($data['payment_due_days'] ?? 14),
            self::normalizeTaxDateMode($data['tax_date_mode'] ?? null),
            self::normalizeDraftOpenMode($data['draft_open_mode'] ?? null),
            self::normalizeReminderDays($data['reminder_days_before'] ?? null),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            !empty($data['increment_month_in_descriptions']) ? 1 : 0,
            !empty($data['auto_issue']) ? 1 : 0,
            !empty($data['auto_send_email']) ? 1 : 0,
            (string) ($data['status'] ?? 'active'),
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $endOfMonth = !empty($data['end_of_month']);
        $dayOfMonth = $endOfMonth ? null : (isset($data['day_of_month']) && $data['day_of_month'] !== null ? (int) $data['day_of_month'] : null);

        // Přepočet next_run_date:
        //  - šablona ještě neběžela (last_run_date IS NULL) → next = anchor_date
        //    (uživatel mění harmonogram před prvním generováním).
        //  - už běží → přemapuj DEN nejbližšího naplánovaného next_run_date dle
        //    nového pravidla (end_of_month / day_of_month) v rámci JEHO měsíce —
        //    bez posunu cyklu. Tím se např. změna „20. v měsíci" → „konec měsíce"
        //    projeví hned na nejbližším vystavení (20.6. → 30.6.), ne až o cyklus dál.
        $cur = $this->db->pdo()->prepare(
            'SELECT last_run_date, next_run_date FROM recurring_invoice_templates WHERE id = ?'
        );
        $cur->execute([$id]);
        $existing = $cur->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty($existing['last_run_date'])) {
            $nextRunDate = (string) $data['anchor_date'];
        } else {
            $nextRunDate = PeriodicityCalculator::snapToDayRule(
                (string) $existing['next_run_date'],
                $endOfMonth,
                $dayOfMonth,
            );
        }

        $sql = 'UPDATE recurring_invoice_templates SET
                client_id = ?, project_id = ?, name = ?,
                frequency = ?, day_of_month = ?, end_of_month = ?,
                anchor_date = ?, end_date = ?,
                next_run_date = ?,
                invoice_type = ?, currency_id = ?, language = ?, payment_method = ?,
                reverse_charge = ?, payment_due_days = ?, tax_date_mode = ?,
                draft_open_mode = ?, reminder_days_before = ?,
                note_above_items = ?, note_below_items = ?,
                increment_month_in_descriptions = ?, auto_issue = ?, auto_send_email = ?
              WHERE id = ?';
        $this->db->pdo()->prepare($sql)->execute([
            (int) $data['client_id'],
            !empty($data['project_id']) ? (int) $data['project_id'] : null,
            (string) $data['name'],
            (string) $data['frequency'],
            $dayOfMonth,
            $endOfMonth ? 1 : 0,
            (string) $data['anchor_date'],
            !empty($data['end_date']) ? (string) $data['end_date'] : null,
            $nextRunDate,
            (string) ($data['invoice_type'] ?? 'invoice'),
            (int) $data['currency_id'],
            (string) ($data['language'] ?? 'cs'),
            (string) ($data['payment_method'] ?? 'bank_transfer'),
            !empty($data['reverse_charge']) ? 1 : 0,
            (int) ($data['payment_due_days'] ?? 14),
            self::normalizeTaxDateMode($data['tax_date_mode'] ?? null),
            self::normalizeDraftOpenMode($data['draft_open_mode'] ?? null),
            self::normalizeReminderDays($data['reminder_days_before'] ?? null),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            !empty($data['increment_month_in_descriptions']) ? 1 : 0,
            !empty($data['auto_issue']) ? 1 : 0,
            !empty($data['auto_send_email']) ? 1 : 0,
            $id,
        ]);
    }

    private static function normalizeTaxDateMode(mixed $value): string
    {
        $v = is_string($value) ? $value : '';
        return $v === 'previous_month_last_day' ? 'previous_month_last_day' : 'same_as_issue';
    }

    private static function normalizeDraftOpenMode(mixed $value): string
    {
        $v = is_string($value) ? $value : '';
        return $v === 'period_start' ? 'period_start' : 'at_issue';
    }

    /** 0 = bez reminderu, jinak 1–14 dní předem. */
    private static function normalizeReminderDays(mixed $value): int
    {
        $n = is_numeric($value) ? (int) $value : 1;
        return max(0, min(14, $n));
    }

    public function replaceItems(int $templateId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')
            ->execute([$templateId]);

        $stmt = $pdo->prepare(
            'INSERT INTO recurring_invoice_template_items
                (template_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($items) as $i => $item) {
            $stmt->execute([
                $templateId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                (int) ($item['vat_rate_id'] ?? 0),
                (int) ($item['order_index'] ?? $i),
            ]);
        }
    }

    /** Posun next_run_date + last_run_date po úspěšném vygenerování faktury. */
    public function advanceSchedule(int $id, string $newNextRunDate, string $lastRunDate, string $newStatus): void
    {
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates
                SET next_run_date = ?, last_run_date = ?, status = ?
              WHERE id = ?'
        )->execute([$newNextRunDate, $lastRunDate, $newStatus, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates SET status = ? WHERE id = ?'
        )->execute([$status, $id]);
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE smaže items.
        // invoices.recurring_template_id má ON DELETE SET NULL → vygenerované faktury zůstanou.
        $this->db->pdo()->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')
            ->execute([$id]);
    }

    private function cast(array $row): array
    {
        $row['id']             = (int) $row['id'];
        $row['supplier_id']    = (int) $row['supplier_id'];
        $row['client_id']      = (int) $row['client_id'];
        $row['project_id']     = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $row['currency_id']    = (int) $row['currency_id'];
        if (array_key_exists('day_of_month', $row)) {
            $row['day_of_month'] = $row['day_of_month'] !== null ? (int) $row['day_of_month'] : null;
        }
        foreach (['end_of_month', 'reverse_charge', 'increment_month_in_descriptions', 'auto_issue', 'auto_send_email'] as $f) {
            if (array_key_exists($f, $row)) $row[$f] = (bool) $row[$f];
        }
        if (array_key_exists('payment_due_days', $row)) {
            $row['payment_due_days'] = (int) $row['payment_due_days'];
        }
        if (array_key_exists('reminder_days_before', $row)) {
            $row['reminder_days_before'] = (int) $row['reminder_days_before'];
        }
        if (array_key_exists('invoices_generated_count', $row)) {
            $row['invoices_generated_count'] = (int) $row['invoices_generated_count'];
        }
        return $row;
    }

    private function castItem(array $row): array
    {
        $row['id']                     = (int) $row['id'];
        $row['template_id']            = (int) $row['template_id'];
        $row['vat_rate_id']            = (int) $row['vat_rate_id'];
        $row['order_index']            = (int) $row['order_index'];
        $row['quantity']               = (float) $row['quantity'];
        $row['unit_price_without_vat'] = (float) $row['unit_price_without_vat'];
        if (isset($row['vat_rate_percent'])) {
            $row['vat_rate_percent'] = (float) $row['vat_rate_percent'];
        }
        return $row;
    }
}
