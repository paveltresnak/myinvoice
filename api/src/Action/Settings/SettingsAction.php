<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Settings — multi-supplier + currencies (per-supplier bankovní účty).
 *
 *   GET  /api/settings/supplier             — aktuální supplier (X-Supplier-Id)
 *   PUT  /api/settings/supplier             — update aktuálního (admin)
 *   GET  /api/suppliers                     — list všech (pro switcher)
 *   POST /api/suppliers                     — nový supplier (admin)
 *   GET  /api/suppliers/{id}                — detail
 *   PUT  /api/suppliers/{id}                — update (admin)
 *   DELETE /api/suppliers/{id}              — smaz (admin, jen pokud nemá data)
 *   GET  /api/settings/currencies           — currencies aktuálního supplier
 *   PUT  /api/settings/currencies/{id}      — update (admin, jen vlastní supplier)
 */
final class SettingsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    /** Aktuální supplier (z X-Supplier-Id middleware). */
    public function getSupplier(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        return $this->respondSupplier($response, $id);
    }

    /** Update aktuálního supplier (admin). */
    public function updateSupplier(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        return $this->updateSupplierById($request, $response, ['id' => (string) $id]);
    }

    /** GET /api/suppliers — list všech (pro switcher). */
    public function listSuppliers(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT s.id, s.company_name, s.display_name, s.ic, s.dic, s.is_vat_payer,
                    s.email, c.iso2 AS country_iso,
                    (SELECT COUNT(*) FROM clients cl  WHERE cl.supplier_id  = s.id) AS clients_count,
                    (SELECT COUNT(*) FROM invoices i  WHERE i.supplier_id   = s.id) AS invoices_count
               FROM supplier s
               JOIN countries c ON c.id = s.country_id
           ORDER BY s.id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['is_vat_payer']   = (bool) $r['is_vat_payer'];
            $r['clients_count']  = (int) $r['clients_count'];
            $r['invoices_count'] = (int) $r['invoices_count'];
        }
        return Json::ok($response, $rows);
    }

    /** GET /api/suppliers/{id}. */
    public function getSupplierById(Request $request, Response $response, array $args): Response
    {
        return $this->respondSupplier($response, (int) ($args['id'] ?? 0));
    }

    /** POST /api/suppliers — nový supplier (admin). */
    public function createSupplier(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $b = (array) ($request->getParsedBody() ?? []);

        $required = ['company_name', 'street', 'city', 'zip', 'email'];
        foreach ($required as $f) {
            if (trim((string) ($b[$f] ?? '')) === '') {
                return Json::error($response, 'validation_failed', "Pole '$f' je povinné.", 400);
            }
        }

        $pdo = $this->db->pdo();

        // Country (default CZ)
        $countryIso = strtoupper((string) ($b['country_iso2'] ?? 'CZ'));
        $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmtCountry->execute([$countryIso]);
        $countryId = (int) $stmtCountry->fetchColumn();
        if ($countryId === 0) $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();

        $defaultVatId = (int) $pdo->query("SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn()
            ?: (int) $pdo->query("SELECT id FROM vat_rates ORDER BY id LIMIT 1")->fetchColumn();

        $pdo->beginTransaction();
        try {
            // 1. Insert supplier (default_currency_id placeholder, opravíme po insertu currencies)
            //    Použijeme libovolnou existující currency_id pro inicializaci, pak nahradíme.
            $bootstrapCurId = (int) $pdo->query("SELECT id FROM currencies ORDER BY id LIMIT 1")->fetchColumn();
            if ($bootstrapCurId === 0) {
                throw new \RuntimeException('V DB neexistuje žádná currency — fresh install vyžaduje setup.php.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                       ic, dic, is_vat_payer, email, phone, web, tagline,
                                       default_currency_id, default_vat_rate_id,
                                       default_payment_due_days, default_hourly_rate)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                (string) $b['company_name'],
                $this->nullable($b, 'display_name') ?: (string) $b['company_name'],
                (string) $b['street'],
                (string) $b['city'],
                (string) $b['zip'],
                $countryId,
                $this->nullable($b, 'ic'),
                $this->nullable($b, 'dic'),
                !empty($b['is_vat_payer']) ? 1 : (!empty($b['dic']) ? 1 : 0),
                (string) $b['email'],
                $this->nullable($b, 'phone'),
                $this->nullable($b, 'web'),
                $this->nullable($b, 'tagline'),
                $bootstrapCurId,
                $defaultVatId ?: 1,
                (int) ($b['default_payment_due_days'] ?? 14),
                (float) ($b['default_hourly_rate'] ?? 1500.00),
            ]);
            $newSupplierId = (int) $pdo->lastInsertId();

            // 2. Seed default currencies pro nového supplier (CZK + EUR, bez bank polí)
            $insertCur = $pdo->prepare(
                'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)'
            );
            $insertCur->execute([$newSupplierId, 'CZK', 'CZK — výchozí', 'Kč', 'Česká koruna', 'Czech Koruna', 2]);
            $newDefaultCurId = (int) $pdo->lastInsertId();
            $insertCur->execute([$newSupplierId, 'EUR', 'EUR — výchozí', '€', 'Euro', 'Euro', 2]);

            // 3. Update supplier.default_currency_id na CZK supplier
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
                ->execute([$newDefaultCurId, $newSupplierId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'create_failed', 'Vytvoření supplier selhalo: ' . $e->getMessage(), 500);
        }

        $this->log($request, 'supplier.created', $newSupplierId, ['company_name' => $b['company_name'], 'ic' => $b['ic'] ?? null]);
        return Json::ok($response, ['id' => $newSupplierId], 201);
    }

    /** PUT /api/suppliers/{id} (admin). */
    public function updateSupplierById(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);

        $body = (array) ($request->getParsedBody() ?? []);

        $allowed = [
            'company_name', 'display_name', 'street', 'city', 'zip', 'country_id',
            'ic', 'dic', 'is_vat_payer', 'email', 'phone', 'web', 'tagline', 'commercial_register',
            'default_currency_id', 'default_vat_rate_id', 'default_payment_due_days',
            'default_hourly_rate', 'auto_send_reminders', 'logo_path', 'signature_path',
            'pohoda_account_code', 'pohoda_centre_code', 'pohoda_activity_code', 'pohoda_contract_code',
        ];
        // Legacy: pokud frontend pošle 'default_currency' jako code, převedeme na id (scoped to supplier)
        if (isset($body['default_currency']) && !isset($body['default_currency_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stmt->execute([$id, strtoupper((string) $body['default_currency'])]);
            $body['default_currency_id'] = (int) $stmt->fetchColumn();
        }
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                $params[] = in_array($f, ['is_vat_payer', 'auto_send_reminders'], true)
                    ? ((int) (bool) $body[$f])
                    : $body[$f];
            }
        }
        if (empty($sets)) return $this->respondSupplier($response, $id);

        $params[] = $id;
        $sql = 'UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->pdo()->prepare($sql)->execute($params);
        $this->log($request, 'supplier.updated', $id, ['fields' => array_keys(array_intersect_key($body, array_flip($allowed)))]);
        return $this->respondSupplier($response, $id);
    }

    /** DELETE /api/suppliers/{id} — jen pokud supplier nemá clients/invoices/currencies s daty. */
    public function deleteSupplierById(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);

        $pdo = $this->db->pdo();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM supplier")->fetchColumn();
        if ($count <= 1) {
            return Json::error($response, 'cannot_delete_last', 'Posledního supplier nelze smazat.', 409);
        }

        foreach (['clients' => 'klientů', 'invoices' => 'faktur'] as $table => $label) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE supplier_id = ?");
            $stmt->execute([$id]);
            $n = (int) $stmt->fetchColumn();
            if ($n > 0) {
                return Json::error($response, 'has_dependencies', "Supplier nelze smazat — má $n $label.", 409);
            }
        }

        // Currencies + invoice_counters jsou per-supplier — smaž je s ním
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'delete_failed', 'Smazání selhalo: ' . $e->getMessage(), 500);
        }

        // MS-P3-4: smaž PDF cache subfolder pro tohoto supplier
        $pdfDir = \MyInvoice\Bootstrap::rootDir() . '/storage/invoices/sup-' . $id;
        if (is_dir($pdfDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pdfDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                if ($f->isDir()) @rmdir($f->getPathname());
                else @unlink($f->getPathname());
            }
            @rmdir($pdfDir);
        }

        $this->log($request, 'supplier.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function respondSupplier(Response $response, int $id): Response
    {
        if ($id <= 0) return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, c.name_cs AS country_name_cs, c.name_en AS country_name_en, c.iso2 AS country_iso,
                    cur.code AS default_currency
               FROM supplier s
               JOIN countries c ON c.id = s.country_id
               JOIN currencies cur ON cur.id = s.default_currency_id
              WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        $row['id']                       = (int) $row['id'];
        $row['is_vat_payer']             = (bool) $row['is_vat_payer'];
        $row['default_vat_rate_id']      = (int) $row['default_vat_rate_id'];
        $row['default_currency_id']      = (int) $row['default_currency_id'];
        $row['default_payment_due_days'] = (int) $row['default_payment_due_days'];
        $row['default_hourly_rate']      = (float) $row['default_hourly_rate'];
        $row['auto_send_reminders']      = (bool) $row['auto_send_reminders'];
        return Json::ok($response, $row);
    }

    private function nullable(array $b, string $key): ?string
    {
        $v = trim((string) ($b[$key] ?? ''));
        return $v === '' ? null : $v;
    }

    public function listCurrencies(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.code, c.label, c.symbol, c.name_cs, c.name_en, c.decimals,
                    c.is_active, c.is_default,
                    c.account_number, c.bank_code, c.bank_name, c.iban, c.bic,
                    (SELECT COUNT(*) FROM invoices i WHERE i.currency_id = c.id) AS invoices_count
               FROM currencies c
              WHERE c.supplier_id = ?
           ORDER BY c.code, c.is_default DESC, c.label'
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']              = (int) $r['id'];
            $r['decimals']        = (int) $r['decimals'];
            $r['is_active']       = (bool) $r['is_active'];
            $r['is_default']      = (bool) $r['is_default'];
            $r['invoices_count']  = (int) $r['invoices_count'];
        }
        return Json::ok($response, $rows);
    }

    public function updateCurrency(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);

        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT code, supplier_id FROM currencies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        if ((int) $row['supplier_id'] !== $sid) {
            return Json::error($response, 'wrong_supplier', 'Tato měna patří jinému supplier.', 403);
        }
        $code = (string) $row['code'];

        $body = (array) ($request->getParsedBody() ?? []);
        $allowed = ['label', 'symbol', 'is_active', 'is_default', 'account_number', 'bank_code', 'bank_name', 'iban', 'bic'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                if (in_array($f, ['is_active', 'is_default'], true)) {
                    $params[] = (int) (bool) $body[$f];
                } else {
                    $params[] = ($body[$f] === '' || $body[$f] === null) ? null : $body[$f];
                }
            }
        }
        if (empty($sets)) {
            return $this->respondCurrencyById($response, $id);
        }

        // Pokud je is_default=1, vypneme default na ostatních řádcích pro stejný code v RÁMCI supplier
        if (array_key_exists('is_default', $body) && (int) (bool) $body['is_default'] === 1) {
            $pdo->prepare('UPDATE currencies SET is_default = 0 WHERE supplier_id = ? AND code = ? AND id <> ?')
                ->execute([$sid, $code, $id]);
        }

        $params[] = $id;
        $sql = 'UPDATE currencies SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($params);

        // Pokud se měnily bank fields, invaliduj PDF cache faktur v této měně (drafty + faktury bez snapshotu)
        $bankFields = ['account_number', 'bank_code', 'bank_name', 'iban', 'bic'];
        $changedBankFields = array_intersect($bankFields, array_keys($body));
        $invalidated = 0;
        if (!empty($changedBankFields)) {
            $invalidated = $this->pdf->invalidateByCurrency($id);
        }

        $this->log($request, 'currency.updated', $id, [
            'code' => $code,
            'fields' => array_keys(array_intersect_key($body, array_flip($allowed))),
            'pdf_invalidated' => $invalidated,
        ]);

        return $this->respondCurrencyById($response, $id);
    }

    private function respondCurrencyById(Response $response, int $id): Response
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                    account_number, bank_code, bank_name, iban, bic
               FROM currencies WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        $row['id']         = (int) $row['id'];
        $row['decimals']   = (int) $row['decimals'];
        $row['is_active']  = (bool) $row['is_active'];
        $row['is_default'] = (bool) $row['is_default'];
        return Json::ok($response, $row);
    }

    // ============================================================================
    // VAT RATES (číselník DPH sazeb)
    // ============================================================================

    public function listVatRates(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT v.id, v.code, v.rate_percent, v.country, v.label_cs, v.label_en, v.is_default,
                    v.is_reverse_charge, v.valid_from, v.valid_to,
                    (SELECT COUNT(*) FROM invoice_items i WHERE i.vat_rate_id = v.id) AS items_count
               FROM vat_rates v
           ORDER BY v.country, v.rate_percent DESC'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']                = (int) $r['id'];
            $r['rate_percent']      = (float) $r['rate_percent'];
            $r['is_default']        = (bool) $r['is_default'];
            $r['is_reverse_charge'] = (bool) $r['is_reverse_charge'];
            $r['items_count']       = (int) $r['items_count'];
        }
        return Json::ok($response, $rows);
    }

    public function createVatRate(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $b = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($b['code'] ?? ''));
        $rate = (float) ($b['rate_percent'] ?? -1);
        if ($code === '' || $rate < 0) {
            return Json::error($response, 'validation_failed', 'code a rate_percent jsou povinné.', 400);
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $code, $rate,
            strtoupper((string) ($b['country'] ?? 'CZ')),
            (string) ($b['label_cs'] ?? $code),
            (string) ($b['label_en'] ?? $code),
            !empty($b['is_default']) ? 1 : 0,
            !empty($b['is_reverse_charge']) ? 1 : 0,
            (string) ($b['valid_from'] ?? date('Y-m-d')),
            !empty($b['valid_to']) ? (string) $b['valid_to'] : null,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        if (!empty($b['is_default'])) $this->makeOnlyDefault($id, (string) ($b['country'] ?? 'CZ'));
        $this->log($request, 'vat_rate.created', $id, ['code' => $code, 'rate' => $rate]);
        return Json::ok($response, ['id' => $id], 201);
    }

    public function updateVatRate(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $b = (array) ($request->getParsedBody() ?? []);
        $allowed = ['code', 'rate_percent', 'country', 'label_cs', 'label_en',
                    'is_default', 'is_reverse_charge', 'valid_from', 'valid_to'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "$f = ?";
                $params[] = in_array($f, ['is_default', 'is_reverse_charge'], true)
                    ? ((int) (bool) $b[$f])
                    : ($b[$f] === '' ? null : $b[$f]);
            }
        }
        if (empty($sets)) return Json::ok($response, ['ok' => true]);
        $params[] = $id;
        $this->db->pdo()->prepare('UPDATE vat_rates SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        if (!empty($b['is_default'])) $this->makeOnlyDefault($id, (string) ($b['country'] ?? 'CZ'));
        $this->log($request, 'vat_rate.updated', $id, ['fields' => array_keys($b)]);
        return Json::ok($response, ['ok' => true]);
    }

    public function deleteVatRate(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_items WHERE vat_rate_id = ?');
        $stmt->execute([$id]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            return Json::error($response, 'has_dependencies',
                "Sazbu nelze smazat — používá ji $count položek faktur. Nastav jí konec platnosti (valid_to).", 409);
        }
        $this->db->pdo()->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$id]);
        $this->log($request, 'vat_rate.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function makeOnlyDefault(int $id, string $country): void
    {
        $this->db->pdo()->prepare(
            'UPDATE vat_rates SET is_default = 0 WHERE id <> ? AND country = ?'
        )->execute([$id, strtoupper($country)]);
    }

    // ============================================================================
    // COUNTRIES (číselník zemí)
    // ============================================================================

    public function listCountries(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT c.id, c.iso2, c.iso3, c.name_cs, c.name_en, c.is_eu,
                    (SELECT COUNT(*) FROM clients cl WHERE cl.country_id = c.id) +
                    (SELECT COUNT(*) FROM supplier s WHERE s.country_id = c.id) AS uses_count
               FROM countries c ORDER BY c.name_cs'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['is_eu']      = (bool) $r['is_eu'];
            $r['uses_count'] = (int) $r['uses_count'];
        }
        return Json::ok($response, $rows);
    }

    public function createCountry(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $b = (array) ($request->getParsedBody() ?? []);
        $iso2 = strtoupper(trim((string) ($b['iso2'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $iso2)) {
            return Json::error($response, 'validation_failed', 'iso2 musí být 2 znaky.', 400);
        }
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO countries (iso2, iso3, name_cs, name_en, is_eu) VALUES (?,?,?,?,?)'
            )->execute([
                $iso2,
                strtoupper((string) ($b['iso3'] ?? '')),
                (string) ($b['name_cs'] ?? ''),
                (string) ($b['name_en'] ?? ''),
                !empty($b['is_eu']) ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            return Json::error($response, 'duplicate', 'Země s tímto iso2 už existuje.', 409);
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->log($request, 'country.created', $id, ['iso2' => $iso2]);
        return Json::ok($response, ['id' => $id], 201);
    }

    public function updateCountry(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $b = (array) ($request->getParsedBody() ?? []);
        $allowed = ['iso3', 'name_cs', 'name_en', 'is_eu'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "$f = ?";
                $params[] = $f === 'is_eu' ? (int) (bool) $b[$f] : $b[$f];
            }
        }
        if (empty($sets)) return Json::ok($response, ['ok' => true]);
        $params[] = $id;
        $this->db->pdo()->prepare('UPDATE countries SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        $this->log($request, 'country.updated', $id, ['fields' => array_keys($b)]);
        return Json::ok($response, ['ok' => true]);
    }

    public function deleteCountry(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE country_id = ?');
        $stmt->execute([$id]);
        $clients = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM supplier WHERE country_id = ?');
        $stmt->execute([$id]);
        $supplier = (int) $stmt->fetchColumn();
        if ($clients > 0 || $supplier > 0) {
            return Json::error($response, 'has_dependencies',
                "Zemi nelze smazat — používá ji $clients klientů + supplier=$supplier.", 409);
        }
        $this->db->pdo()->prepare('DELETE FROM countries WHERE id = ?')->execute([$id]);
        $this->log($request, 'country.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    // ============================================================================
    // CURRENCIES — create/delete (update existující)
    // ============================================================================

    public function createCurrency(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $b = (array) ($request->getParsedBody() ?? []);
        $code = strtoupper(trim((string) ($b['code'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return Json::error($response, 'validation_failed', 'code musí být 3 znaky.', 400);
        }
        $label = trim((string) ($b['label'] ?? "$code — výchozí"));
        if ($label === '') $label = $code;

        $pdo = $this->db->pdo();
        // Existuje code v rámci tohoto supplier?
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM currencies WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$sid, $code]);
        $existsForCode = (int) $stmt->fetchColumn();
        $isDefault = array_key_exists('is_default', $b) ? ((int) (bool) $b['is_default']) : ($existsForCode === 0 ? 1 : 0);

        if ($isDefault === 1 && $existsForCode > 0) {
            $pdo->prepare('UPDATE currencies SET is_default = 0 WHERE supplier_id = ? AND code = ?')->execute([$sid, $code]);
        }

        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, bank_name, iban, bic)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $sid,
            $code,
            $label,
            (string) ($b['symbol'] ?? $code),
            (string) ($b['name_cs'] ?? $code),
            (string) ($b['name_en'] ?? $code),
            (int) ($b['decimals'] ?? 2),
            array_key_exists('is_active', $b) ? ((int) (bool) $b['is_active']) : 1,
            $isDefault,
            ($b['account_number'] ?? '') !== '' ? (string) $b['account_number'] : null,
            ($b['bank_code'] ?? '') !== '' ? (string) $b['bank_code'] : null,
            ($b['bank_name'] ?? '') !== '' ? (string) $b['bank_name'] : null,
            ($b['iban'] ?? '') !== '' ? (string) $b['iban'] : null,
            ($b['bic'] ?? '') !== '' ? (string) $b['bic'] : null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $this->log($request, 'currency.created', $newId, ['supplier_id' => $sid, 'code' => $code, 'label' => $label]);
        return Json::ok($response, ['id' => $newId, 'code' => $code], 201);
    }

    public function deleteCurrency(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT supplier_id FROM currencies WHERE id = ?');
        $stmt->execute([$id]);
        $ownerSid = (int) $stmt->fetchColumn();
        if ($ownerSid === 0) return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        if ($ownerSid !== $sid) return Json::error($response, 'wrong_supplier', 'Tato měna patří jinému supplier.', 403);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE currency_id = ?');
        $stmt->execute([$id]);
        $invoices = (int) $stmt->fetchColumn();
        if ($invoices > 0) {
            return Json::error($response, 'has_dependencies', "Měnu nelze smazat — má $invoices faktur.", 409);
        }
        $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        $this->log($request, 'currency.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    private function log(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'supplier', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
