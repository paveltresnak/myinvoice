<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * CRUD pro přijaté faktury (purchase invoices) — paralel k InvoiceRepository,
 * ale pro doklady, které dostáváme od dodavatelů.
 *
 * Klíčové rozdíly oproti vystaveným fakturám:
 *   - vendor_id místo client_id (vendor = protistrana, řádek v `clients` s is_vendor=1)
 *   - status lifecycle: draft → received → booked → paid (+ cancelled)
 *   - žádný approval / sent / reminder flow
 *   - varsymbol generovaný z purchase_invoice_counters: PF-YYYYMM-NNNN
 *
 * Bezpečnostní pravidla:
 *   - Vždy filtrovat WHERE supplier_id = ? (tenant scope)
 *   - Mutating operace ověřit ownership přes find() s supplier_id
 *   - Žádné raw SQL s user input — vždy prepared statements
 */
final class PurchaseInvoiceRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Najde fakturu jen pokud patří danému tenantovi.
     * Vrací null jak pro neexistující, tak pro cizí (consistent — neprozrazuje cross-tenant existenci).
     */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.*,
                    c.company_name AS vendor_company_name, c.ic AS vendor_ic, c.dic AS vendor_dic,
                    c.main_email AS vendor_main_email, c.language AS vendor_language,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    pcur.code AS payment_currency, pcur.symbol AS payment_currency_symbol
               FROM purchase_invoices pi
               JOIN clients c        ON c.id   = pi.vendor_id
               JOIN currencies cur   ON cur.id = pi.currency_id
          LEFT JOIN currencies pcur  ON pcur.id = pi.payment_currency_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->castInvoice($row);
        $row['items'] = $this->itemsFor($id);
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        $row['totals'] = [
            'without_vat'         => $row['total_without_vat'],
            'vat'                 => $row['total_vat'],
            'with_vat'            => $row['total_with_vat'],
            'rounding'            => $row['rounding'],
            'advance_paid_amount' => $row['advance_paid_amount'],
            'amount_to_pay'       => $row['amount_to_pay'],
        ];
        return $row;
    }

    /**
     * Items dané přijaté faktury, seřazené.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsFor(int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.purchase_invoice_id, pii.description, pii.quantity, pii.unit,
                    pii.unit_price_without_vat, pii.vat_rate_id, pii.vat_rate_snapshot,
                    pii.total_without_vat, pii.total_vat, pii.total_with_vat,
                    pii.order_index, pii.vat_classification_code,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM purchase_invoice_items pii
               JOIN vat_rates vr ON vr.id = pii.vat_rate_id
              WHERE pii.purchase_invoice_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /**
     * Seznam přijatých faktur tenantu, seskupený po měsících podle COALESCE(tax_date, issue_date).
     *
     * Output: ['data' => [{month, count, totals_per_currency, invoices: [...]}], 'meta' => ...]
     *
     * Filtry:
     *   supplier_id (povinné — tenant scope)
     *   q, status, document_kind, vendor_id, year, month, date_from, date_to, currency, unpaid_only, overdue
     */
    public function listGroupedByMonth(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        if ($supplierId === 0) {
            return ['data' => [], 'meta' => ['total' => 0]];
        }

        $where = ['pi.supplier_id = ?'];
        $params = [$supplierId];

        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "pi.status IN ($place)";
            foreach ($statuses as $s) $params[] = (string) $s;
        }
        if (!empty($filters['document_kind'])) {
            $kinds = is_array($filters['document_kind']) ? $filters['document_kind'] : [$filters['document_kind']];
            $place = implode(',', array_fill(0, count($kinds), '?'));
            $where[] = "pi.document_kind IN ($place)";
            foreach ($kinds as $k) $params[] = (string) $k;
        }
        if (!empty($filters['vendor_id'])) {
            $where[] = 'pi.vendor_id = ?';
            $params[] = (int) $filters['vendor_id'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(COALESCE(pi.tax_date, pi.issue_date)) = ?';
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'MONTH(COALESCE(pi.tax_date, pi.issue_date)) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper((string) $filters['currency']);
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "pi.status IN ('received','booked')";
        }
        if (!empty($filters['overdue'])) {
            $where[] = "pi.status IN ('received','booked') AND pi.due_date <= CURDATE()";
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query / unexpected match
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(pi.varsymbol LIKE ? OR pi.vendor_invoice_number LIKE ? OR c.company_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        // MariaDB 10.2+ window function — COUNT(*) OVER() vrací total v každém řádku.
        // Místo 2 query (COUNT + SELECT s LIMIT) jeden round-trip, žádný race condition
        // mezi count a paginated select, žádný duplicate WHERE / JOIN parsing.
        $selectTotal = $perPage > 0 ? ', COUNT(*) OVER() AS total_rows' : '';

        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                       pi.vendor_id, pi.supplier_id,
                       pi.issue_date, pi.tax_date, pi.due_date, pi.received_at,
                       pi.currency_id, cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                       pi.total_without_vat, pi.total_vat, pi.total_with_vat,
                       pi.advance_paid_amount, pi.amount_to_pay,
                       pi.status, pi.booked_at, pi.paid_at, pi.cancelled_at,
                       c.company_name AS vendor_company_name, c.ic AS vendor_ic,
                       DATE_FORMAT(COALESCE(pi.tax_date, pi.issue_date), '%Y-%m') AS month_bucket
                       {$selectTotal}
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE $whereSql
                 ORDER BY COALESCE(pi.tax_date, pi.issue_date) DESC, pi.id DESC";

        $offset = 0;
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= ' LIMIT ? OFFSET ?';
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) $stmt->bindValue($idx++, $v);
        if ($perPage > 0) {
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // total_rows extrahujeme z prvního řádku (window function vrací stejnou hodnotu
        // v každém řádku). Pokud výsledek je prázdný a používáme pagination, total=0.
        $total = null;
        if ($perPage > 0) {
            $total = !empty($rows) ? (int) $rows[0]['total_rows'] : 0;
        }

        $grouped = [];
        foreach ($rows as $row) {
            unset($row['total_rows']); // metadata, nepatří do invoice payloadu
            $row = $this->castInvoice($row);
            $month = (string) $row['month_bucket'];
            if (!isset($grouped[$month])) {
                $grouped[$month] = [
                    'month' => $month,
                    'count' => 0,
                    'totals_per_currency' => [],
                    'invoices' => [],
                ];
            }
            $grouped[$month]['invoices'][] = $row;
            $grouped[$month]['count']++;

            // Nákupy: nezahrnujeme draft (koncepty), cancelled (storno)
            if (!in_array($row['status'], ['draft', 'cancelled'], true)) {
                $cur = $row['currency'];
                if (!isset($grouped[$month]['totals_per_currency'][$cur])) {
                    $grouped[$month]['totals_per_currency'][$cur] = [
                        'currency'    => $cur,
                        'without_vat' => 0.0,
                        'vat'         => 0.0,
                        'with_vat'    => 0.0,
                    ];
                }
                $grouped[$month]['totals_per_currency'][$cur]['without_vat'] += (float) $row['total_without_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['vat']         += (float) $row['total_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['with_vat']    += (float) $row['total_with_vat'];
            }
        }
        foreach ($grouped as &$g) {
            $g['totals_per_currency'] = array_values($g['totals_per_currency']);
        }
        unset($g);

        $meta = ['total' => $total ?? array_sum(array_column($grouped, 'count'))];
        if ($perPage > 0) {
            $meta['page']     = $page;
            $meta['per_page'] = $perPage;
            $meta['pages']    = (int) ceil(($total ?? 0) / max(1, $perPage));
        }

        return ['data' => array_values($grouped), 'meta' => $meta];
    }

    /**
     * Vytvoří draft přijaté faktury. Vrací nové id.
     *
     * Pravidla:
     *   - vendor_id MUSÍ patřit do supplier_id (volající kontroluje přes SupplierGuard nad clients)
     *   - varsymbol je volitelný — pokud chybí, vygeneruje se až při přechodu na received
     *   - vendor_snapshot je povinné (uložíme aktuální vendor data jako immutable)
     */
    public function createDraft(array $data, int $userId, int $supplierId): int
    {
        $pdo = $this->db->pdo();

        $vendorId = (int) ($data['vendor_id'] ?? 0);
        if ($vendorId === 0) {
            throw new \InvalidArgumentException('vendor_id chybí');
        }

        // Sanity check: vendor existuje a patří tenantovi
        $stmt = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $stmt->execute([$vendorId]);
        $vendorSupplier = (int) $stmt->fetchColumn();
        if ($vendorSupplier !== $supplierId) {
            throw new \InvalidArgumentException("Vendor #$vendorId nepatří tomuto tenantovi.");
        }

        // Vendor invoice number — povinné, validace max 50 znaků
        $vendorInvoiceNumber = trim((string) ($data['vendor_invoice_number'] ?? ''));
        if ($vendorInvoiceNumber === '') {
            throw new \InvalidArgumentException('vendor_invoice_number je povinné');
        }
        if (strlen($vendorInvoiceNumber) > 50) {
            throw new \InvalidArgumentException('vendor_invoice_number má max 50 znaků');
        }

        $documentKind = (string) ($data['document_kind'] ?? 'invoice');
        if (!in_array($documentKind, ['invoice', 'receipt', 'credit_note', 'advance'], true)) {
            $documentKind = 'invoice';
        }

        $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
        if ($manualVarsymbol === '') {
            $manualVarsymbol = null;
        } elseif (strlen($manualVarsymbol) > 20) {
            throw new \InvalidArgumentException('varsymbol má max 20 znaků');
        }

        // Snapshot vendoru — buď z payloadu, nebo načteme z DB
        $vendorSnapshot = $data['vendor_snapshot'] ?? null;
        if (!is_array($vendorSnapshot)) {
            $vendorSnapshot = $this->buildVendorSnapshot($vendorId);
        }

        $sql = 'INSERT INTO purchase_invoices
            (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
             issue_date, tax_date, due_date, received_at,
             currency_id, exchange_rate, exchange_rate_date, exchange_rate_source,
             reverse_charge, language, note_above_items, note_below_items,
             vendor_snapshot, own_snapshot,
             advance_paid_amount,
             payment_currency_id, payment_exchange_rate,
             paid_amount_payment_ccy, paid_amount_invoice_ccy, exchange_diff_base,
             status, vat_classification_code, expense_category_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $supplierId,
            $vendorId,
            $manualVarsymbol,
            $vendorInvoiceNumber,
            $documentKind,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            (int) $data['currency_id'],
            isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            empty($data['exchange_rate_date']) ? null : (string) $data['exchange_rate_date'],
            (string) ($data['exchange_rate_source'] ?? 'cnb'),
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            json_encode($vendorSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            isset($data['own_snapshot']) && is_array($data['own_snapshot'])
                ? json_encode($data['own_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            (float) ($data['advance_paid_amount'] ?? 0),
            isset($data['payment_currency_id']) && $data['payment_currency_id'] ? (int) $data['payment_currency_id'] : null,
            isset($data['payment_exchange_rate']) ? (float) $data['payment_exchange_rate'] : null,
            isset($data['paid_amount_payment_ccy']) ? (float) $data['paid_amount_payment_ccy'] : null,
            isset($data['paid_amount_invoice_ccy']) ? (float) $data['paid_amount_invoice_ccy'] : null,
            isset($data['exchange_diff_base']) ? (float) $data['exchange_diff_base'] : null,
            isset($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            isset($data['expense_category_id']) && $data['expense_category_id'] ? (int) $data['expense_category_id'] : null,
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update draft přijaté faktury. Volající má ověřit, že je `status='draft'`.
     */
    public function updateDraft(int $id, array $data, int $supplierId): void
    {
        $hasVarsymbol = array_key_exists('varsymbol', $data);
        $manualVarsymbol = null;
        if ($hasVarsymbol) {
            $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
            if ($manualVarsymbol === '') {
                $manualVarsymbol = null;
            } elseif (strlen($manualVarsymbol) > 20) {
                throw new \InvalidArgumentException('varsymbol má max 20 znaků');
            }
        }

        $documentKind = (string) ($data['document_kind'] ?? 'invoice');
        if (!in_array($documentKind, ['invoice', 'receipt', 'credit_note', 'advance'], true)) {
            $documentKind = 'invoice';
        }

        $vendorInvoiceNumber = trim((string) ($data['vendor_invoice_number'] ?? ''));
        if ($vendorInvoiceNumber === '') {
            throw new \InvalidArgumentException('vendor_invoice_number je povinné');
        }
        if (strlen($vendorInvoiceNumber) > 50) {
            throw new \InvalidArgumentException('vendor_invoice_number má max 50 znaků');
        }

        $sql = 'UPDATE purchase_invoices SET
                vendor_id = ?, vendor_invoice_number = ?, document_kind = ?,
                issue_date = ?, tax_date = ?, due_date = ?, received_at = ?,
                currency_id = ?, exchange_rate = ?, exchange_rate_date = ?, exchange_rate_source = ?,
                reverse_charge = ?, language = ?,
                note_above_items = ?, note_below_items = ?,
                advance_paid_amount = ?,
                payment_currency_id = ?, payment_exchange_rate = ?,
                paid_amount_payment_ccy = ?, paid_amount_invoice_ccy = ?, exchange_diff_base = ?,
                vat_classification_code = ?, expense_category_id = ?'
              . ($hasVarsymbol ? ', varsymbol = ?' : '')
              . ' WHERE id = ? AND supplier_id = ?';

        $params = [
            (int) $data['vendor_id'],
            $vendorInvoiceNumber,
            $documentKind,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            (int) $data['currency_id'],
            isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            empty($data['exchange_rate_date']) ? null : (string) $data['exchange_rate_date'],
            (string) ($data['exchange_rate_source'] ?? 'cnb'),
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            isset($data['payment_currency_id']) && $data['payment_currency_id'] ? (int) $data['payment_currency_id'] : null,
            isset($data['payment_exchange_rate']) ? (float) $data['payment_exchange_rate'] : null,
            isset($data['paid_amount_payment_ccy']) ? (float) $data['paid_amount_payment_ccy'] : null,
            isset($data['paid_amount_invoice_ccy']) ? (float) $data['paid_amount_invoice_ccy'] : null,
            isset($data['exchange_diff_base']) ? (float) $data['exchange_diff_base'] : null,
            isset($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            isset($data['expense_category_id']) && $data['expense_category_id'] ? (int) $data['expense_category_id'] : null,
        ];
        if ($hasVarsymbol) $params[] = $manualVarsymbol;
        $params[] = $id;
        $params[] = $supplierId;

        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Smaže fakturu — ON DELETE CASCADE smaže i items.
     * Volající kontroluje, že je status=draft.
     */
    public function delete(int $id, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM purchase_invoices WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /**
     * Přepíše items (smaže staré + insertne nové).
     * Volá se z SetItems action; následuje recompute z PurchaseInvoiceCalculator.
     */
    public function replaceItems(int $purchaseInvoiceId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')
            ->execute([$purchaseInvoiceId]);

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)'
        );

        $vatRates = $this->vatRateMap();

        foreach (array_values($items) as $i => $item) {
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            $stmt->execute([
                $purchaseInvoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                (int) ($item['order_index'] ?? $i),
                isset($item['vat_classification_code']) ? (string) $item['vat_classification_code'] : null,
            ]);
        }
    }

    /**
     * Zafixuje exchange_rate + date + source. NULL rate = vyresetovat.
     */
    public function setExchangeRate(int $id, ?float $rate, ?string $rateDate, string $source, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET exchange_rate = ?, exchange_rate_date = ?, exchange_rate_source = ?
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$rate, $rateDate, $source, $id, $supplierId]);
    }

    /**
     * Status transition. Volající ověří povolené přechody (state machine).
     * Side-efekty (timestamp pole) tady — booked_at, paid_at, cancelled_at.
     */
    public function setStatus(int $id, string $newStatus, int $supplierId, ?string $paidDate = null): void
    {
        if (!in_array($newStatus, ['draft', 'received', 'booked', 'paid', 'cancelled'], true)) {
            throw new \InvalidArgumentException("Invalid status: $newStatus");
        }

        $sets = ['status = ?'];
        $params = [$newStatus];

        if ($newStatus === 'booked') {
            $sets[] = 'booked_at = NOW()';
        } elseif ($newStatus === 'paid') {
            $sets[] = 'paid_at = ?';
            $params[] = $paidDate ?? date('Y-m-d');
        } elseif ($newStatus === 'cancelled') {
            $sets[] = 'cancelled_at = NOW()';
        } elseif ($newStatus === 'received') {
            // Reverse transition (paid→received / cancelled→received) — vyčisti timestamp
            // odpovídajícího "exit" stavu, aby data byla konzistentní.
            $sets[] = 'paid_at = NULL';
            $sets[] = 'cancelled_at = NULL';
        }

        $params[] = $id;
        $params[] = $supplierId;

        $sql = 'UPDATE purchase_invoices SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?';
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Vygeneruje další varsymbol PF-YYYYMM-NNNN pro daný tenant + období.
     * Atomicky inkrementuje counter (FOR UPDATE / INSERT … ON DUPLICATE KEY).
     */
    public function nextVarsymbol(int $supplierId, ?string $period = null): string
    {
        $period = $period ?? date('Ym');
        $pdo = $this->db->pdo();

        // Atomický increment přes INSERT … ON DUPLICATE KEY UPDATE.
        // Pro MariaDB platí, že LAST_INSERT_ID(expr) vrátí nově nastavenou hodnotu.
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        );
        $stmt->execute([$supplierId, $period]);
        $n = (int) $pdo->lastInsertId();
        if ($n === 0) $n = 1;

        return sprintf('PF-%s-%04d', $period, $n);
    }

    /**
     * Přiřadí varsymbol fakture, pokud ho nemá. Idempotentní — pokud už ho má, nedělá nic.
     */
    public function ensureVarsymbol(int $id, int $supplierId): string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT varsymbol, issue_date FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Purchase invoice #$id not found.");
        }
        if (!empty($row['varsymbol'])) {
            return (string) $row['varsymbol'];
        }

        $period = date('Ym', strtotime((string) $row['issue_date']));
        $varsymbol = $this->nextVarsymbol($supplierId, $period);

        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$varsymbol, $id, $supplierId]);
        return $varsymbol;
    }

    /**
     * Update totálů z items (volá PurchaseInvoiceCalculator).
     */
    public function updateTotals(int $id, float $withoutVat, float $vat, float $withVat, float $rounding): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET total_without_vat = ?, total_vat = ?, total_with_vat = ?, rounding = ?
                        WHERE id = ?')
            ->execute([$withoutVat, $vat, $withVat, $rounding, $id]);
    }

    /**
     * Vrátí ID faktury s daným pdf_hash u tenanta, nebo null. Pro dedup při PDF uploadu / inbox scanu.
     */
    public function findIdByPdfHash(int $supplierId, string $sha256): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND pdf_hash = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $sha256]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Set archived PDF metadata po úspěšném uložení souboru na disk.
     */
    public function setPdfMetadata(int $id, int $supplierId, string $path, string $hash, int $size, ?string $originalName): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices
                SET pdf_path = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_original_name = ?, pdf_uploaded_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        )->execute([$path, $hash, $size, $originalName, $id, $supplierId]);
    }

    /**
     * Update totals na úrovni jedné položky (volá Calculator).
     */
    public function updateItemTotals(int $itemId, float $withoutVat, float $vatAmount, float $withVat): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoice_items
                          SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
                        WHERE id = ?')
            ->execute([$withoutVat, $vatAmount, $withVat, $itemId]);
    }

    /**
     * @return array<int, float> map [vat_rate_id => rate_percent]
     */
    public function vatRateMap(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    /**
     * Postaví vendor_snapshot z aktuálního stavu clients row.
     */
    private function buildVendorSnapshot(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.company_name, c.first_name, c.last_name, c.ic, c.dic,
                    c.street, c.city, c.zip, c.main_email, c.phone, c.language,
                    co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c
               JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?'
        );
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? ['id' => $vendorId] : $row;
    }

    /**
     * Group items by vat rate for breakdown table.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function buildVatBreakdown(array $items): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $rate = (float) ($item['vat_rate_snapshot'] ?? 0);
            $key = number_format($rate, 2, '.', '');
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'vat_rate'    => $rate,
                    'without_vat' => 0.0,
                    'vat'         => 0.0,
                    'with_vat'    => 0.0,
                ];
            }
            $buckets[$key]['without_vat'] += (float) ($item['total_without_vat'] ?? 0);
            $buckets[$key]['vat']         += (float) ($item['total_vat'] ?? 0);
            $buckets[$key]['with_vat']    += (float) ($item['total_with_vat'] ?? 0);
        }
        ksort($buckets);
        return array_values($buckets);
    }

    private function castInvoice(array $row): array
    {
        foreach (['id', 'supplier_id', 'vendor_id', 'currency_id', 'payment_currency_id',
                  'created_by', 'pdf_size_bytes', 'expense_category_id'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null) $row[$f] = (int) $row[$f];
        }
        $row['reverse_charge'] = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        foreach ([
            'total_without_vat', 'total_vat', 'total_with_vat', 'rounding',
            'advance_paid_amount', 'amount_to_pay',
            'exchange_rate', 'payment_exchange_rate',
            'paid_amount_payment_ccy', 'paid_amount_invoice_ccy', 'exchange_diff_base',
        ] as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) $row[$f] = (float) $row[$f];
        }
        // Decode JSON snapshots (DB column je longtext, ne JSON type)
        foreach (['vendor_snapshot', 'own_snapshot'] as $f) {
            if (isset($row[$f]) && is_string($row[$f]) && $row[$f] !== '') {
                $decoded = json_decode($row[$f], true);
                if (is_array($decoded)) $row[$f] = $decoded;
            }
        }
        return $row;
    }

    private function castItem(array $row): array
    {
        foreach (['id', 'purchase_invoice_id', 'vat_rate_id', 'order_index'] as $f) {
            if (isset($row[$f])) $row[$f] = (int) $row[$f];
        }
        foreach ([
            'quantity', 'unit_price_without_vat', 'vat_rate_snapshot',
            'total_without_vat', 'total_vat', 'total_with_vat',
        ] as $f) {
            if (isset($row[$f])) $row[$f] = (float) $row[$f];
        }
        return $row;
    }
}
