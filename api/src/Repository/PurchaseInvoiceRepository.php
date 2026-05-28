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
 *   - varsymbol generovaný z purchase_invoice_counters: {PP}{YYMM}{CCC} (např.
 *     PF2602001), kde PP dle daňového typu (PF/PN plný, KU/KN krácený, NU/NN bez nároku)
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

        // Propojení se zálohou (advance):
        //  - linked_advance   = záloha, kterou tato finální faktura vyúčtovává
        //  - settled_by       = finální faktura vyúčtovávající tuto zálohu (reverzně)
        //  - advance_link_suggestion = AI návrh (suggest & confirm), čeká na potvrzení
        $row['linked_advance'] = $row['advance_purchase_invoice_id'] !== null
            ? $this->briefFor((int) $row['advance_purchase_invoice_id'], $supplierId)
            : null;
        $row['advance_link_suggestion'] = $row['advance_link_suggested_id'] !== null
            ? $this->briefFor((int) $row['advance_link_suggested_id'], $supplierId)
            : null;
        $row['settled_by'] = ($row['document_kind'] ?? '') === 'advance'
            ? $this->settledByFor($id, $supplierId)
            : null;
        return $row;
    }

    /**
     * Stručné shrnutí přijaté faktury (pro propojení/odkazy v detailu). NULL pokud
     * neexistuje nebo nepatří tenantovi.
     *
     * @return array{id:int, varsymbol:?string, vendor_invoice_number:?string,
     *               document_kind:?string, status:string, issue_date:?string,
     *               total_with_vat:float, currency:string}|null
     */
    private function briefFor(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) return null;
        return [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
        ];
    }

    /** Finální faktura, která vyúčtovává tuto zálohu (reverzní pohled). */
    private function settledByFor(int $advanceId, int $supplierId): ?array
    {
        $id = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE advance_purchase_invoice_id = ? AND supplier_id = ? LIMIT 1'
        );
        $id->execute([$advanceId, $supplierId]);
        $finalId = $id->fetchColumn();
        return $finalId !== false ? $this->briefFor((int) $finalId, $supplierId) : null;
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
                    pii.order_index, pii.vat_classification_code, pii.is_fixed_asset,
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
     * Seznam přijatých faktur tenantu, seskupený po měsících podle **issue_date**
     * (datum vystavení faktury dodavatelem).
     *
     * Pozn.: NEpoužíváme DUZP (tax_date) protože dodavatel může vystavit fakturu
     * v jiném měsíci než je DUZP — typicky DUZP konec měsíce, vystavení následující
     * měsíc. Z účetního hlediska user fakturu uplatní v měsíci, kdy ji obdrží/byla
     * vystavena dodavatelem, ne v měsíci DUZP. DPH přiznání má vlastní logic dle
     * tax_date — viz DphPriznaniBuilder.
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
            $where[] = 'YEAR(pi.issue_date) = ?';
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'MONTH(pi.issue_date) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'pi.issue_date >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'pi.issue_date <= ?';
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
        if (!empty($filters['needs_review'])) {
            $where[] = "pi.extraction_warning IS NOT NULL";
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
                       pi.exchange_rate, pi.exchange_rate_date,
                       pi.total_without_vat, pi.total_vat, pi.total_with_vat,
                       pi.advance_paid_amount, pi.amount_to_pay,
                       pi.status, pi.booked_at, pi.paid_at, pi.cancelled_at,
                       pi.extraction_warning, pi.vat_deduction, pi.vat_deduction_percent, pi.tax_deductible,
                       c.company_name AS vendor_company_name, c.ic AS vendor_ic,
                       DATE_FORMAT(pi.issue_date, '%Y-%m') AS month_bucket
                       {$selectTotal}
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE $whereSql
                 ORDER BY pi.issue_date DESC, pi.id DESC";

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
             status, vat_classification_code, vat_deduction, vat_deduction_percent, tax_deductible, is_fixed_asset, expense_category_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?, ?, ?, ?, ?)';

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
            in_array($data['vat_deduction'] ?? 'full', ['full', 'none', 'proportional'], true) ? (string) ($data['vat_deduction'] ?? 'full') : 'full',
            max(0.0, min(100.0, (float) ($data['vat_deduction_percent'] ?? 100))),
            (array_key_exists('tax_deductible', $data) && !$data['tax_deductible']) ? 0 : 1,
            !empty($data['is_fixed_asset']) ? 1 : 0,
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
                vat_classification_code = ?, vat_deduction = ?, vat_deduction_percent = ?, tax_deductible = ?, is_fixed_asset = ?, expense_category_id = ?'
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
            in_array($data['vat_deduction'] ?? 'full', ['full', 'none', 'proportional'], true) ? (string) ($data['vat_deduction'] ?? 'full') : 'full',
            max(0.0, min(100.0, (float) ($data['vat_deduction_percent'] ?? 100))),
            (array_key_exists('tax_deductible', $data) && !$data['tax_deductible']) ? 0 : 1,
            !empty($data['is_fixed_asset']) ? 1 : 0,
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
                 total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?)'
        );

        $vatRates = $this->vatRateMap();

        // Reverse charge + země dodavatele — určuje klasifikační kód:
        //   CZ vendor → '40'/'41'/'42' (tuzemsko podle sazby)
        //   CZ vendor + RC → '5' (přenesená povinnost)
        //   EU vendor s 0% → '24' (přijetí služby z EU) — typický pro Anthropic, GitHub apod.
        //   non-EU vendor s 0% → '25' (dovoz ze 3. země)
        $metaStmt = $pdo->prepare(
            'SELECT pi.reverse_charge, co.iso2
               FROM purchase_invoices pi
               JOIN clients c     ON c.id  = pi.vendor_id
               JOIN countries co  ON co.id = c.country_id
              WHERE pi.id = ?'
        );
        $metaStmt->execute([$purchaseInvoiceId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['reverse_charge' => 0, 'iso2' => 'CZ'];
        $reverseCharge = (bool) $meta['reverse_charge'];
        $countryIso = (string) ($meta['iso2'] ?? 'CZ');

        foreach (array_values($items) as $i => $item) {
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            // Auto-klasifikace pro DPH přiznání / KH — pokud caller (importer / manual create)
            // neuvedl explicitní kód, default podle sazby + RC + country. Bez tohohle by
            // faktura NEDORAZILA do výkazů (VatClassificationMapper SKIPNE code=NULL).
            $code = $item['vat_classification_code'] ?? null;
            if ($code === null) {
                $code = self::defaultClassificationCode($rate, $reverseCharge, $countryIso);
            }
            $stmt->execute([
                $purchaseInvoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                (int) ($item['order_index'] ?? $i),
                $code !== null ? (string) $code : null,
                !empty($item['is_fixed_asset']) ? 1 : 0,
            ]);
        }
    }

    /**
     * Default vat_classification_code podle sazby + RC + země dodavatele pro PŘIJATÉ faktury.
     *
     * Mapování:
     *   CZ vendor:
     *     RC + 21%      → '5'  (přenesená povinnost tuzemsko)
     *     21% standard  → '40' (přijaté plnění tuzemsko — základní)
     *     12% standard  → '41' (přijaté plnění tuzemsko — snížená)
     *     0%            → null (osvobozeno bez nároku — user si vybere)
     *   EU vendor (DE, SK, AT, IE, …):
     *     0% → '24' (přijetí služby z EU — typický pro Anthropic, GitHub, Microsoft Ireland)
     *     21%/12% → tuzemsko sazby (vendor v EU vykazuje českou DPH — vzácné)
     *   Non-EU vendor (US, UK, atd.):
     *     0% → '25' (dovoz ze 3. země)
     *     jinak tuzemsko sazby
     *
     * Pro pořízení zboží z EU ('23' místo služby '24') si user změní ručně —
     * default 0%+EU mapujeme na služby, což je častější CZ IT use case.
     */
    public static function defaultClassificationCode(
        float $rate,
        bool $reverseCharge,
        ?string $vendorCountryIso2 = null,
    ): ?string {
        $r = (int) round($rate);
        $iso = strtoupper((string) ($vendorCountryIso2 ?? 'CZ'));
        $euCountries = [
            'AT','BE','BG','HR','CY','DK','EE','FI','FR','DE','GR','HU','IE','IT',
            'LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        ];
        $isEu = in_array($iso, $euCountries, true);
        $isForeign = $iso !== 'CZ' && $iso !== '';

        // Zahraniční dodavatel + nulová sazba → EU služby (acquire) / dovoz
        if ($isForeign && $r === 0) {
            return $isEu ? '24' : '25';
        }
        // EU vendor + RC + 21 % → pořízení zboží z JČS (kód 23, ř. 3 + ř. 43 mirror + KH A.2).
        // Vzácnější použití (vendor obvykle fakturuje bez DPH), ale když má 21 % sazbu
        // (typicky reverse-charge invoice s vyčíslenou daní pro info), tohle je správně.
        if ($isEu && $reverseCharge && $r >= 21) return '23';
        // CZ tuzemsko (nebo zahraniční vendor s CZ DPH, vzácné)
        if ($reverseCharge && $r >= 21) return '5';
        if ($r >= 21)                   return '40';
        if ($r >= 5 && $r <= 15)        return '41';
        return null;
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
     * Propojí finální fakturu ($finalId) se zálohou ($advanceId). Vazba se ukládá
     * NA FINÁLNÍ fakturu (advance_purchase_invoice_id), 1:1 (UNIQUE index).
     *
     * Validace: oba doklady patří tenantovi, $advanceId je advance, $finalId NENÍ
     * advance, a oba mají stejného dodavatele. Pokud finální nemá vyplněnou zálohu
     * (advance_paid_amount = 0), doplní ji = total_with_vat zálohy, aby amount_to_pay
     * ukázal zbývající úhradu. Návrh AI (advance_link_suggested_id) se zároveň vyčistí.
     *
     * @throws \RuntimeException při porušení validace
     */
    public function linkAdvance(int $finalId, int $advanceId, int $supplierId): void
    {
        if ($finalId === $advanceId) {
            throw new \RuntimeException('Nelze propojit doklad sám se sebou.');
        }
        $final   = $this->find($finalId, $supplierId);
        $advance = $this->find($advanceId, $supplierId);
        if ($final === null || $advance === null) {
            throw new \RuntimeException('Doklad nenalezen.');
        }
        if (($advance['document_kind'] ?? '') !== 'advance') {
            throw new \RuntimeException('Propojit lze jen se zálohovou fakturou (advance).');
        }
        if (($final['document_kind'] ?? '') === 'advance') {
            throw new \RuntimeException('Zálohu nelze vyúčtovávat jinou zálohou.');
        }
        if ((int) $final['vendor_id'] !== (int) $advance['vendor_id']) {
            throw new \RuntimeException('Záloha i finální faktura musí být od stejného dodavatele.');
        }

        $advanceTotal = (float) $advance['total_with_vat'];
        $setAdvancePaid = ((float) ($final['advance_paid_amount'] ?? 0)) == 0.0;

        $sql = 'UPDATE purchase_invoices
                   SET advance_purchase_invoice_id = ?, advance_link_suggested_id = NULL'
             . ($setAdvancePaid ? ', advance_paid_amount = ?' : '')
             . ' WHERE id = ? AND supplier_id = ?';
        $params = $setAdvancePaid
            ? [$advanceId, $advanceTotal, $finalId, $supplierId]
            : [$advanceId, $finalId, $supplierId];
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /** Zruší propojení finální faktury se zálohou (advance_paid_amount ponecháme — ruční korekce). */
    public function unlinkAdvance(int $finalId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_purchase_invoice_id = NULL
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$finalId, $supplierId]);
    }

    /** Uloží AI návrh propojení se zálohou (suggest & confirm) — neaplikuje vazbu. */
    public function suggestAdvanceLink(int $finalId, int $advanceId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_link_suggested_id = ?
                        WHERE id = ? AND supplier_id = ? AND advance_purchase_invoice_id IS NULL')
            ->execute([$advanceId, $finalId, $supplierId]);
    }

    /** Zahodí AI návrh propojení. */
    public function dismissAdvanceSuggestion(int $finalId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_link_suggested_id = NULL
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$finalId, $supplierId]);
    }

    /**
     * Kandidáti k propojení: nespárované zálohy (document_kind='advance') stejného
     * dodavatele jako finální faktura $finalId, které ještě nejsou navázané na žádnou
     * finální fakturu. Seřazené od nejnovějších.
     *
     * @return list<array<string,mixed>>
     */
    public function advanceCandidates(int $finalId, int $supplierId): array
    {
        $final = $this->find($finalId, $supplierId);
        if ($final === null) return [];
        // Řazení: nejdřív stejná měna, pak nejbližší HRUBÁ částka (total_with_vat) k
        // finální faktuře — záloha bývá ve výši celé/části faktury. Porovnáváme proti
        // total_with_vat (před odečtem zálohy), NE amount_to_pay (to bývá 0, když je
        // faktura už uhrazená zálohou). Nakonec nejnovější.
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.vendor_id = ?
                AND pi.document_kind = 'advance'
                AND pi.status != 'cancelled'
                AND pi.id <> ?
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                 WHERE s.advance_purchase_invoice_id = pi.id)
              ORDER BY (pi.currency_id = ?) DESC,
                       ABS(pi.total_with_vat - ?) ASC,
                       pi.issue_date DESC, pi.id DESC
              LIMIT 50"
        );
        $stmt->execute([
            $supplierId, (int) $final['vendor_id'], $finalId,
            (int) $final['currency_id'], (float) $final['total_with_vat'],
        ]);
        return array_map(fn (array $r) => [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => (string) $r['document_kind'],
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Najde nespárovanou zálohu (advance) téhož dodavatele, jejíž číslo dokladu nebo
     * variabilní symbol odpovídá odkazu z faktury (např. "zaplaceno zálohou č. X").
     * Porovnává bez mezer (variabilní symbol může být na dokladu rozdělený). Vrací
     * id pro AI návrh propojení, nebo null. Konzervativní (přesná shoda) — návrh
     * uživatel stejně potvrzuje.
     */
    public function findAdvanceByReference(int $supplierId, int $vendorId, string $reference): ?int
    {
        $norm = preg_replace('/\s+/', '', trim($reference)) ?? '';
        if ($norm === '') return null;
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id FROM purchase_invoices pi
              WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                AND pi.document_kind = 'advance'
                AND pi.status != 'cancelled'
                AND (REPLACE(COALESCE(pi.vendor_invoice_number,''), ' ', '') = ?
                  OR REPLACE(COALESCE(pi.varsymbol,''), ' ', '') = ?)
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                 WHERE s.advance_purchase_invoice_id = pi.id)
              ORDER BY pi.issue_date DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $vendorId, $norm, $norm]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /**
     * Vygeneruje další varsymbol {PP}{YYMM}{CCC} (např. PF2602001) pro tenant + období.
     * Atomicky inkrementuje counter (FOR UPDATE / INSERT … ON DUPLICATE KEY).
     */
    public function nextVarsymbol(int $supplierId, ?string $period = null, string $prefix = 'PF'): string
    {
        $period = $period ?? date('Ym');
        $pdo = $this->db->pdo();

        // Atomický increment přes INSERT … ON DUPLICATE KEY UPDATE.
        // Pro MariaDB platí, že LAST_INSERT_ID(expr) vrátí nově nastavenou hodnotu.
        // Counter je sdílený per (supplier, období) napříč prefixy — číslo je tedy
        // souvislé přes všechny přijaté doklady období, prefix jen značí daňový typ.
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        );
        $stmt->execute([$supplierId, $period]);
        $n = (int) $pdo->lastInsertId();
        if ($n === 0) $n = 1;

        // Formát {PP}{YYMM}{CCC} bez oddělovačů, např. PF2602001. Counter key je
        // YYYYMM (period), ve varsymbolu se používá jen dvojčíslí roku (YY).
        // %03d = min. 3 místa; když by měsíc měl >999 dokladů, počítadlo přirozeně
        // přeleze na 4+ místa (PF26021000…) — pořadí zůstane korektní.
        $prefix = preg_match('/^[A-Z]{2}$/', $prefix) ? $prefix : 'PF';
        return sprintf('%s%s%03d', $prefix, substr($period, 2, 4), $n);
    }

    /**
     * Po změně daňového uplatnění (vat_deduction / tax_deductible) přepíše PREFIX
     * auto-generovaného interního čísla na ten odpovídající novému typu — číselnou
     * řadu (YYMM+CCC) ponechá. Např. PF2602001 → NN2602001.
     *
     * No-op pro: draft (bez varsymbolu), ručně zadaná / cizí čísla (nevypadají jako
     * auto-generovaná) a když už prefix sedí. Pozná i starý formát PF-YYYYMM-NNNN.
     */
    public function reprefixVarsymbol(int $id, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT varsymbol, vat_deduction, tax_deductible FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return;

        $vs = (string) ($row['varsymbol'] ?? '');
        if ($vs === '') return; // draft / bez čísla

        $cur = substr($vs, 0, 2);
        $rest = substr($vs, 2);
        // Auto-číslo = známý prefix + buď nový formát (YYMMCCC… = ≥7 číslic),
        // nebo starý PF-YYYYMM-NNNN. Cokoli jiného (ruční / cizí) neměníme.
        $isAuto = in_array($cur, ['PF', 'PN', 'KU', 'KN', 'NU', 'NN'], true)
            && (preg_match('/^\d{7,}$/', $rest) === 1 || preg_match('/^-\d{6}-\d+$/', $rest) === 1);
        if (!$isAuto) return;

        $expected = self::varsymbolPrefix((string) ($row['vat_deduction'] ?? 'full'), (bool) ($row['tax_deductible'] ?? 1));
        if ($cur === $expected) return;

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$expected . $rest, $id, $supplierId]);
    }

    /**
     * Prefix interního čísla přijaté faktury podle daňového typu:
     *   plný nárok   → PF (uznatelný) / PN (neuznatelný)
     *   krácený §75  → KU / KN
     *   bez nároku   → NU / NN
     */
    public static function varsymbolPrefix(string $vatDeduction, bool $taxDeductible): string
    {
        return match ($vatDeduction) {
            'none'         => $taxDeductible ? 'NU' : 'NN',
            'proportional' => $taxDeductible ? 'KU' : 'KN',
            default        => $taxDeductible ? 'PF' : 'PN',
        };
    }

    /**
     * Přiřadí varsymbol fakture, pokud ho nemá. Idempotentní — pokud už ho má, nedělá nic.
     */
    public function ensureVarsymbol(int $id, int $supplierId): string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT varsymbol, issue_date, vat_deduction, tax_deductible FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Purchase invoice #$id not found.");
        }
        if (!empty($row['varsymbol'])) {
            return (string) $row['varsymbol'];
        }

        $period = date('Ym', strtotime((string) $row['issue_date']));
        $prefix = self::varsymbolPrefix((string) ($row['vat_deduction'] ?? 'full'), (bool) ($row['tax_deductible'] ?? 1));
        $varsymbol = $this->nextVarsymbol($supplierId, $period, $prefix);

        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$varsymbol, $id, $supplierId]);
        return $varsymbol;
    }

    /**
     * Update totálů z items (volá PurchaseInvoiceCalculator).
     */
    /** Update jen rounding pole (volá AI import po extract). */
    public function setRounding(int $id, int $supplierId, float $rounding): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET rounding = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$rounding, $id, $supplierId]);
    }

    /**
     * Zapíše (nebo vyčistí) diagnostický popis problému z AI extrakce.
     * UI ho zobrazí jako žluté upozornění, aby si uživatel data ověřil
     * (typicky: AI sečetla subtotaly jako další položky).
     */
    public function setExtractionWarning(int $id, int $supplierId, ?string $warning): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET extraction_warning = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$warning, $id, $supplierId]);
    }

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
     * Vrátí ID faktury s daným vendor_invoice_number u (tenant, vendor, issue_date) tuple,
     * nebo null pokud neexistuje. Respektuje UNIQUE KEY uq_pi_vendor_invoice — caller
     * tím detekuje "tahle faktura už je v systému" před voláním createDraft (které by
     * jinak hodilo SQLSTATE 23000 duplicate key).
     */
    public function findIdByVendorInvoice(int $supplierId, int $vendorId, string $vendorInvoiceNumber, string $issueDate): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ?
                AND vendor_invoice_number = ? AND issue_date = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $vendorId, $vendorInvoiceNumber, $issueDate]);
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
                  'created_by', 'pdf_size_bytes', 'expense_category_id',
                  'advance_purchase_invoice_id', 'advance_link_suggested_id'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null) $row[$f] = (int) $row[$f];
        }
        $row['reverse_charge'] = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        $row['is_fixed_asset'] = isset($row['is_fixed_asset']) ? (bool) $row['is_fixed_asset'] : false;
        $row['tax_deductible'] = !array_key_exists('tax_deductible', $row) || (bool) $row['tax_deductible'];
        $vatDeduction = (string) ($row['vat_deduction'] ?? '');
        $row['vat_deduction'] = in_array($vatDeduction, ['full', 'none', 'proportional'], true) ? $vatDeduction : 'full';
        $row['vat_deduction_percent'] = isset($row['vat_deduction_percent']) ? (float) $row['vat_deduction_percent'] : 100.0;
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
        $row['is_fixed_asset'] = isset($row['is_fixed_asset']) ? (bool) $row['is_fixed_asset'] : false;
        return $row;
    }
}
