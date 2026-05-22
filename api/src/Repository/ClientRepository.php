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
        // Role filter — backend-side aby paginace + total counter byly korektní.
        // Frontend dříve filtroval client-side, ale to dávalo špatné "X z Y" (vendors 15 z 15
        // i když jich je 45) — filtroval jen načtenou stránku, ne celou tabulku.
        $role = (string) ($filters['role'] ?? 'all');
        if ($role === 'vendors') {
            $where[] = 'c.is_vendor = 1';
        } elseif ($role === 'customers') {
            // is_customer default 1 v migraci 0026a — explicit !=0 pokrývá i historická data.
            $where[] = 'c.is_customer <> 0';
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query DoS / nečekanou shodu
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(c.company_name LIKE ? OR c.ic LIKE ? OR c.dic LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q . '%';
            $params[] = $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        // Count
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM clients c WHERE $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Role counts pro tab badge (bez stránkování, bez role-filtru, ale se zbylými filtry
        // jako archived + q + supplier_id). UI ukáže "Klienti (X) | Dodavatelé (Y) | Vše (Z)".
        $whereForCounts = $where;
        $paramsForCounts = $params;
        // Odstranit role-podmínku z whereForCounts (je v něm jako 'c.is_vendor = 1' apod.)
        $whereForCounts = array_values(array_filter(
            $whereForCounts,
            fn ($w) => $w !== 'c.is_vendor = 1' && $w !== 'c.is_customer <> 0'
        ));
        $whereCountsSql = implode(' AND ', $whereForCounts);
        $stmtCounts = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN c.is_customer <> 0 THEN 1 ELSE 0 END) AS customers,
                SUM(CASE WHEN c.is_vendor   =  1 THEN 1 ELSE 0 END) AS vendors,
                COUNT(*) AS all_clients
             FROM clients c
            WHERE $whereCountsSql"
        );
        $stmtCounts->execute($paramsForCounts);
        $roleCounts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['customers' => 0, 'vendors' => 0, 'all_clients' => 0];

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
                       c.reverse_charge, c.is_customer, c.is_vendor,
                       c.payment_due_default, c.hourly_rate,
                       c.archived_at, co.iso2 AS country_iso2,
                       (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id AND p.status = 'active' AND p.archived_at IS NULL) AS active_projects_count,
                       COALESCE(crc.revenue, 0) AS revenue,
                       crc.last_invoice_date,
                       COALESCE(crc.invoice_count, 0) AS invoice_count,
                       COALESCE(pi_agg.costs, 0) AS costs,
                       COALESCE(pi_agg.purchase_count, 0) AS purchase_count,
                       pi_agg.last_purchase_date
                  FROM clients c
                  JOIN countries  co  ON co.id  = c.country_id
                  JOIN currencies cur ON cur.id = c.currency_default_id
             LEFT JOIN client_revenue_cache crc ON crc.client_id = c.id AND crc.currency_id = c.currency_default_id
             LEFT JOIN (
                       -- Costs sumarizace přes vendory. Multi-currency:
                       -- EUR/USD/... přepočítáme na CZK přes pi.exchange_rate (CNB k DUZP).
                       -- Bez exchange_rate (CZK řádky) je multiplier 1.
                       -- Výsledek `costs` je tedy v CZK (tenant base ccy), nezávisle na
                       -- vendor.currency_default. UI zobrazí jako Kč.
                       SELECT pi.vendor_id,
                              SUM(pi.total_with_vat * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs,
                              COUNT(*) AS purchase_count,
                              MAX(pi.issue_date) AS last_purchase_date
                         FROM purchase_invoices pi
                    LEFT JOIN currencies cur ON cur.id = pi.currency_id
                        WHERE pi.supplier_id = ?
                          AND pi.status NOT IN ('draft', 'cancelled')
                     GROUP BY pi.vendor_id
                   ) pi_agg ON pi_agg.vendor_id = c.id
                 WHERE $whereSql
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        // supplier_id pro pi_agg subquery (purchase costs) — bind PŘED whereSql params,
        // protože subquery v FROM clauseu je evaluated jako první v SQL parser order.
        $stmt->bindValue($idx++, (int) ($filters['supplier_id'] ?? 0), PDO::PARAM_INT);
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
                'role_counts' => [
                    'all'       => (int) $roleCounts['all_clients'],
                    'customers' => (int) $roleCounts['customers'],
                    'vendors'   => (int) $roleCounts['vendors'],
                ],
            ],
        ];
    }

    public function create(array $data, int $supplierId): int
    {
        $countryId = $this->countryIdFromIso2((string) ($data['country_iso2'] ?? 'CZ'));
        $currencyId = $this->resolveCurrencyId($data, $supplierId);

        // Role flagy — default is_customer=1, is_vendor=0 (BC); ovládá frontend formulář.
        $isCustomer = array_key_exists('is_customer', $data) ? (int) (bool) $data['is_customer'] : 1;
        $isVendor   = array_key_exists('is_vendor',   $data) ? (int) (bool) $data['is_vendor']   : 0;
        if ($isCustomer === 0 && $isVendor === 0) {
            // Default fallback — entita musí mít aspoň jednu roli
            $isCustomer = 1;
        }

        $sql = 'INSERT INTO clients
            (supplier_id, company_name, first_name, last_name, ic, dic, street, city, zip, country_id,
             main_email, phone, language, currency_default_id, reverse_charge,
             is_customer, is_vendor,
             auto_send_reminders, payment_due_default, hourly_rate, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
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
            $isCustomer,
            $isVendor,
            array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1,
            isset($data['payment_due_default']) ? (int) $data['payment_due_default'] : null,
            (float) ($data['hourly_rate'] ?? 0),
            $this->nullable($data, 'note'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Označí klienta jako dodavatele (is_vendor=1). Idempotentní — pokud je už označen,
     * nedělá nic. Volá se z CreatePurchaseInvoiceAction po ověření vendor scope.
     * is_customer flag se NEMĚNÍ — klient může být současně zákazník i dodavatel.
     */
    public function markAsVendor(int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE clients SET is_vendor = 1 WHERE id = ? AND is_vendor = 0')
            ->execute([$id]);
    }

    /**
     * Označí klienta jako zákazníka (is_customer=1). Symetrické s markAsVendor.
     * Volá se např. při importu vystavené faktury pro nový kontakt.
     */
    public function markAsCustomer(int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE clients SET is_customer = 1 WHERE id = ? AND is_customer = 0')
            ->execute([$id]);
    }

    public function update(int $id, array $data): void
    {
        $pdo = $this->db->pdo();

        // Klient nemůže měnit supplier — odvodíme z aktuálního DB záznamu pro currency lookup
        $stmt = $pdo->prepare('SELECT supplier_id, is_customer, is_vendor FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new \InvalidArgumentException("Client #$id nenalezen.");
        }
        $supplierId = (int) $current['supplier_id'];

        // Role flagy — pokud chybí v payloadu, zachovat aktuální hodnotu (BC).
        $newIsCustomer = array_key_exists('is_customer', $data) ? (int) (bool) $data['is_customer'] : (int) $current['is_customer'];
        $newIsVendor   = array_key_exists('is_vendor',   $data) ? (int) (bool) $data['is_vendor']   : (int) $current['is_vendor'];

        // Lock check: nelze vypnout is_customer pokud klient má vydané faktury.
        if ((int) $current['is_customer'] === 1 && $newIsCustomer === 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
            $stmt->execute([$id]);
            $cnt = (int) $stmt->fetchColumn();
            if ($cnt > 0) {
                throw new \InvalidArgumentException(
                    "Klient nemůže přestat být zákazníkem — má {$cnt} vystavených faktur. " .
                    'Pro skrytí ze seznamu použij archivaci.'
                );
            }
        }
        // Symmetric lock pro is_vendor proti purchase_invoices.
        if ((int) $current['is_vendor'] === 1 && $newIsVendor === 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE vendor_id = ?');
            $stmt->execute([$id]);
            $cnt = (int) $stmt->fetchColumn();
            if ($cnt > 0) {
                throw new \InvalidArgumentException(
                    "Klient nemůže přestat být dodavatelem — má {$cnt} přijatých faktur. " .
                    'Pro skrytí ze seznamu použij archivaci.'
                );
            }
        }
        // Nesmí se vypnout obě role současně (entita by neměla existovat).
        if ($newIsCustomer === 0 && $newIsVendor === 0) {
            throw new \InvalidArgumentException('Klient musí mít alespoň jednu roli (zákazník nebo dodavatel).');
        }

        $countryId = $this->countryIdFromIso2((string) ($data['country_iso2'] ?? 'CZ'));
        $currencyId = $this->resolveCurrencyId($data, $supplierId);

        $sql = 'UPDATE clients SET
                company_name = ?, first_name = ?, last_name = ?, ic = ?, dic = ?,
                street = ?, city = ?, zip = ?, country_id = ?,
                main_email = ?, phone = ?, language = ?, currency_default_id = ?,
                reverse_charge = ?, is_customer = ?, is_vendor = ?,
                auto_send_reminders = ?, payment_due_default = ?,
                hourly_rate = ?, note = ?
                WHERE id = ?';
        $stmt = $pdo->prepare($sql);
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
            $newIsCustomer,
            $newIsVendor,
            array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1,
            isset($data['payment_due_default']) ? (int) $data['payment_due_default'] : null,
            (float) ($data['hourly_rate'] ?? 0),
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
        if (array_key_exists('is_customer', $row)) $row['is_customer'] = (bool) $row['is_customer'];
        if (array_key_exists('is_vendor', $row))   $row['is_vendor']   = (bool) $row['is_vendor'];
        if (array_key_exists('auto_send_reminders', $row)) {
            $row['auto_send_reminders'] = (bool) $row['auto_send_reminders'];
        }
        if (array_key_exists('active_projects_count', $row)) {
            $row['active_projects_count'] = (int) $row['active_projects_count'];
        }
        if (isset($row['payment_due_default'])) {
            $row['payment_due_default'] = $row['payment_due_default'] !== null ? (int) $row['payment_due_default'] : null;
        }
        if (array_key_exists('hourly_rate', $row)) {
            $row['hourly_rate'] = (float) $row['hourly_rate'];
        }
        if (array_key_exists('revenue', $row))           $row['revenue'] = (float) $row['revenue'];
        if (array_key_exists('costs', $row))             $row['costs'] = (float) $row['costs'];
        if (array_key_exists('purchase_count', $row))    $row['purchase_count'] = (int) $row['purchase_count'];
        if (array_key_exists('last_purchase_date', $row)) $row['last_purchase_date'] = $row['last_purchase_date'] ?: null;
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
