<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro expense_categories — kategorie nákladů přijatých faktur.
 *
 * Per tenant (supplier_id). UNIQUE (supplier_id, code) — uživatel nemůže
 * vytvořit dvě kategorie se stejným kódem v rámci tenant.
 */
final class ExpenseCategoryRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeArchived = false): array
    {
        $sql = 'SELECT id, code, label, fixed_or_var, display_order, archived, created_at,
                       (SELECT COUNT(*) FROM purchase_invoices WHERE expense_category_id = expense_categories.id) AS purchases_count
                  FROM expense_categories
                 WHERE supplier_id = ?';
        if (!$includeArchived) $sql .= ' AND archived = 0';
        $sql .= ' ORDER BY display_order ASC, label ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, fixed_or_var, display_order, archived, created_at
               FROM expense_categories WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * @param array{code:string, label:string, fixed_or_var?:string, display_order?:int} $data
     */
    public function create(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO expense_categories (supplier_id, code, label, fixed_or_var, display_order)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['label'],
            in_array($data['fixed_or_var'] ?? 'variable', ['fixed', 'variable'], true)
                ? $data['fixed_or_var']
                : 'variable',
            (int) ($data['display_order'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE expense_categories
                SET code = ?, label = ?, fixed_or_var = ?, display_order = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['label'],
            in_array($data['fixed_or_var'] ?? 'variable', ['fixed', 'variable'], true)
                ? $data['fixed_or_var']
                : 'variable',
            (int) ($data['display_order'] ?? 0),
            !empty($data['archived']) ? 1 : 0,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard delete — pokud žádná faktura nepoužívá. Jinak soft (archived=1).
     */
    public function delete(int $id, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        // Count usage
        $stmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM purchase_invoices WHERE expense_category_id = ?) AS h_count,
                    (SELECT COUNT(*) FROM purchase_invoice_items WHERE expense_category_id = ?) AS i_count'
        );
        $stmt->execute([$id, $id]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) $usage['h_count'] + (int) $usage['i_count'];

        if ($total === 0) {
            $del = $pdo->prepare('DELETE FROM expense_categories WHERE id = ? AND supplier_id = ?');
            $del->execute([$id, $supplierId]);
            return ['deleted' => true, 'archived' => false];
        }
        // Soft delete — zachová historii odkazů
        $arch = $pdo->prepare('UPDATE expense_categories SET archived = 1 WHERE id = ? AND supplier_id = ?');
        $arch->execute([$id, $supplierId]);
        return ['deleted' => false, 'archived' => true, 'usage_count' => $total];
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['display_order'] = (int) $r['display_order'];
        $r['archived'] = (bool) $r['archived'];
        if (isset($r['purchases_count'])) $r['purchases_count'] = (int) $r['purchases_count'];
        return $r;
    }
}
