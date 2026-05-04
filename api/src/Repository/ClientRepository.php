<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ClientRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2,
                    cur.code AS currency_default
               FROM clients c
               JOIN countries co ON co.id = c.country_id
               JOIN currencies cur ON cur.id = c.currency_default_id
              WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->cast($row) : null;
    }

    public function list(array $filters = [], int $page = 1, int $perPage = 20, string $sort = 'name'): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'c.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['archived'])) {
            $where[] = 'c.archived_at IS NOT NULL';
        } else {
            $where[] = 'c.archived_at IS NULL';
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query DoS / nečekanou shodu
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(c.company_name LIKE ? OR c.ic LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        // Count
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM clients c WHERE $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Whitelist řazení (defense proti SQLi přes user input)
        $orderBy = match ($sort) {
            'revenue'       => 'revenue DESC, c.company_name',
            'last_activity' => 'last_invoice_date IS NULL, last_invoice_date DESC, c.company_name',
            default         => 'c.company_name',
        };

        // Page — LIMIT/OFFSET přes bindValue(PARAM_INT) pro defense-in-depth proti SQLi
        $offset = max(0, ($page - 1) * $perPage);
        // Cache `client_revenue_cache` — primární řádek vybíráme přes c.currency_default_id
        $sql = "SELECT c.id, c.supplier_id, c.company_name, c.ic, c.dic, c.main_email, c.language,
                       c.currency_default_id, cur.code AS currency_default,
                       c.reverse_charge, c.archived_at, co.iso2 AS country_iso2,
                       (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id AND p.status = 'active' AND p.archived_at IS NULL) AS active_projects_count,
                       COALESCE(crc.revenue, 0) AS revenue,
                       crc.last_invoice_date,
                       COALESCE(crc.invoice_count, 0) AS invoice_count
                  FROM clients c
                  JOIN countries  co  ON co.id  = c.country_id
                  JOIN currencies cur ON cur.id = c.currency_default_id
             LEFT JOIN client_revenue_cache crc ON crc.client_id = c.id AND crc.currency_id = c.currency_default_id
                 WHERE $whereSql
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map(fn (array $r) => $this->cast($r), $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function create(array $data, int $supplierId): int
    {
        $countryId = $this->countryIdFromIso2((string) ($data['country_iso2'] ?? 'CZ'));
        $currencyId = $this->resolveCurrencyId($data, $supplierId);

        $sql = 'INSERT INTO clients
            (supplier_id, company_name, first_name, last_name, ic, dic, street, city, zip, country_id,
             main_email, phone, language, currency_default_id, reverse_charge, auto_send_reminders,
             payment_due_default, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId,
            (string) $data['company_name'],
            $this->nullable($data, 'first_name'),
            $this->nullable($data, 'last_name'),
            $this->nullable($data, 'ic'),
            $this->nullable($data, 'dic'),
            (string) $data['street'],
            (string) $data['city'],
            (string) $data['zip'],
            $countryId,
            (string) $data['main_email'],
            $this->nullable($data, 'phone'),
            (string) ($data['language'] ?? 'cs'),
            $currencyId,
            !empty($data['reverse_charge']) ? 1 : 0,
            array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1,
            isset($data['payment_due_default']) ? (int) $data['payment_due_default'] : null,
            $this->nullable($data, 'note'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        // Klient nemůže měnit supplier — odvodíme z aktuálního DB záznamu pro currency lookup
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $supplierId = (int) $stmt->fetchColumn();
        $countryId = $this->countryIdFromIso2((string) ($data['country_iso2'] ?? 'CZ'));
        $currencyId = $this->resolveCurrencyId($data, $supplierId);

        $sql = 'UPDATE clients SET
                company_name = ?, first_name = ?, last_name = ?, ic = ?, dic = ?,
                street = ?, city = ?, zip = ?, country_id = ?,
                main_email = ?, phone = ?, language = ?, currency_default_id = ?,
                reverse_charge = ?, auto_send_reminders = ?, payment_due_default = ?, note = ?
                WHERE id = ?';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            (string) $data['company_name'],
            $this->nullable($data, 'first_name'),
            $this->nullable($data, 'last_name'),
            $this->nullable($data, 'ic'),
            $this->nullable($data, 'dic'),
            (string) $data['street'],
            (string) $data['city'],
            (string) $data['zip'],
            $countryId,
            (string) $data['main_email'],
            $this->nullable($data, 'phone'),
            (string) ($data['language'] ?? 'cs'),
            $currencyId,
            !empty($data['reverse_charge']) ? 1 : 0,
            array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1,
            isset($data['payment_due_default']) ? (int) $data['payment_due_default'] : null,
            $this->nullable($data, 'note'),
            $id,
        ]);
    }

    /**
     * Resolve currency_id z `currency_default_id` (preferováno) nebo z `currency_default` (legacy code lookup).
     * Lookup je SCOPED per supplier — currencies patří jednomu supplier.
     * Pokud je dáno explicitní currency_default_id, ověří že patří danému supplier.
     */
    private function resolveCurrencyId(array $data, int $supplierId): int
    {
        if (isset($data['currency_default_id'])) {
            $id = (int) $data['currency_default_id'];
            $check = $this->db->pdo()->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
            $check->execute([$id, $supplierId]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException("Currency #$id nepatří supplier #$supplierId.");
            }
            return $id;
        }
        $code = strtoupper((string) ($data['currency_default'] ?? 'CZK'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Currency not found: $code (supplier #$supplierId)");
        }
        return (int) $id;
    }

    public function archive(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public function unarchive(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET archived_at = NULL WHERE id = ?')->execute([$id]);
    }

    public function projectsForClient(int $clientId, int $limit = 10): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.name, p.status, cur.code AS currency, p.hourly_rate, p.payment_due_days, p.project_number
               FROM projects p
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.client_id = ? AND p.archived_at IS NULL
              ORDER BY p.status = "active" DESC, p.name
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function countryIdFromIso2(string $iso2): int
    {
        $iso2 = strtoupper($iso2);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmt->execute([$iso2]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $stmt->execute(['CZ']);
            $id = $stmt->fetchColumn();
        }
        return (int) $id;
    }

    private function cast(array $row): array
    {
        $row['id']                    = (int) $row['id'];
        if (isset($row['country_id'])) $row['country_id'] = (int) $row['country_id'];
        if (isset($row['supplier_id'])) $row['supplier_id'] = (int) $row['supplier_id'];
        if (isset($row['currency_default_id'])) $row['currency_default_id'] = (int) $row['currency_default_id'];
        $row['reverse_charge']        = (bool) ($row['reverse_charge'] ?? 0);
        if (array_key_exists('auto_send_reminders', $row)) {
            $row['auto_send_reminders'] = (bool) $row['auto_send_reminders'];
        }
        if (array_key_exists('active_projects_count', $row)) {
            $row['active_projects_count'] = (int) $row['active_projects_count'];
        }
        if (isset($row['payment_due_default'])) {
            $row['payment_due_default'] = $row['payment_due_default'] !== null ? (int) $row['payment_due_default'] : null;
        }
        if (array_key_exists('revenue', $row))           $row['revenue'] = (float) $row['revenue'];
        if (array_key_exists('last_invoice_date', $row)) $row['last_invoice_date'] = $row['last_invoice_date'] ?: null;
        if (array_key_exists('invoice_count', $row))     $row['invoice_count'] = (int) $row['invoice_count'];
        return $row;
    }

    private function nullable(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
