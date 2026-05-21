<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro vat_classifications — kódy MF ČR pro DPH přiznání + KH.
 *
 * Globální seed (supplier_id IS NULL, ze migrace 0037) + per-tenant overrides.
 * Tenant může přidat custom kód, který se aplikuje jen pro něj.
 */
final class VatClassificationRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * List kódů — globální + tenant overrides. Filter na direction (sale/purchase/both).
     *
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, ?string $direction = null, bool $includeArchived = false): array
    {
        $where = ['(supplier_id IS NULL OR supplier_id = ?)'];
        $params = [$supplierId];
        if (!$includeArchived) {
            $where[] = 'archived = 0';
        }
        if ($direction !== null) {
            $where[] = '(direction = ? OR direction = "both")';
            $params[] = $direction;
        }
        $sql = 'SELECT id, supplier_id, code, label, direction, dphdp3_line, kh_section,
                       vat_rate, is_reverse_charge, display_order, archived, created_at
                  FROM vat_classifications
                 WHERE ' . implode(' AND ', $where) .
               ' ORDER BY supplier_id IS NULL DESC, display_order ASC, code ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM vat_classifications WHERE id = ? AND (supplier_id IS NULL OR supplier_id = ?)'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function create(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO vat_classifications
                (supplier_id, code, label, direction, dphdp3_line, kh_section,
                 vat_rate, is_reverse_charge, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['label'],
            in_array($data['direction'] ?? 'both', ['sale', 'purchase', 'both'], true)
                ? $data['direction'] : 'both',
            !empty($data['dphdp3_line']) ? (string) $data['dphdp3_line'] : null,
            !empty($data['kh_section']) ? (string) $data['kh_section'] : null,
            isset($data['vat_rate']) ? (float) $data['vat_rate'] : null,
            !empty($data['is_reverse_charge']) ? 1 : 0,
            (int) ($data['display_order'] ?? 100),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        // Pozor: globální kódy (supplier_id IS NULL) nelze editovat per-tenant
        $existing = $this->find($id, $supplierId);
        if ($existing === null) return false;
        if ($existing['supplier_id'] === null) {
            throw new \RuntimeException('Globální kódy nelze editovat. Vytvoř custom kód pro váš tenant.');
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE vat_classifications
                SET label = ?, direction = ?, dphdp3_line = ?, kh_section = ?,
                    vat_rate = ?, is_reverse_charge = ?, display_order = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['label'],
            in_array($data['direction'] ?? 'both', ['sale', 'purchase', 'both'], true)
                ? $data['direction'] : 'both',
            !empty($data['dphdp3_line']) ? (string) $data['dphdp3_line'] : null,
            !empty($data['kh_section']) ? (string) $data['kh_section'] : null,
            isset($data['vat_rate']) ? (float) $data['vat_rate'] : null,
            !empty($data['is_reverse_charge']) ? 1 : 0,
            (int) ($data['display_order'] ?? 100),
            !empty($data['archived']) ? 1 : 0,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $existing = $this->find($id, $supplierId);
        if ($existing === null) return false;
        if ($existing['supplier_id'] === null) {
            throw new \RuntimeException('Globální kódy nelze smazat.');
        }
        // Soft: archived=1, hard delete by mohl zlomit FK přes vat_classification_code (varchar, ne id)
        $stmt = $this->db->pdo()->prepare('UPDATE vat_classifications SET archived = 1 WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
        $r['vat_rate'] = $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null;
        $r['is_reverse_charge'] = (bool) $r['is_reverse_charge'];
        $r['display_order'] = (int) $r['display_order'];
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }
}
