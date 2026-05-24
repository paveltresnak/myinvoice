<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use Psr\Log\LoggerInterface;

/**
 * iDoklad import orchestrátor.
 *
 * Volaný background workerem (api/bin/import-worker.php). Stahuje:
 *   1. Contacts          → clients (dedup přes idoklad_id)
 *   2. IssuedInvoices    → invoices (dedup přes idoklad_id) — vč. dobropisů (InvoiceType=3)
 *   3. ReceivedInvoices  → purchase_invoices (dedup přes idoklad_id)
 *
 * Pro každý záznam:
 *   - Check existence (supplier_id, idoklad_id) → skip pokud existuje
 *   - Insert nový + nastavit idoklad_id
 *   - Update progress každých 10 items + appendLog
 *
 * Cancellation: každých 10 items check cancel_requested → graceful exit.
 *
 * Date parsing fallback: ReceivedInvoices.DateOfIssue je často NULL, pak
 * DateOfAccountingEvent (per fork bug fix `Fix ReceivedInvoices date parsing`).
 */
final class IdokladImportService
{
    private const PROGRESS_FLUSH_EVERY = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly IdokladClient $idoklad,
        private readonly ImportJobRepository $jobs,
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly InvoiceCalculator $invCalc,
        private readonly PurchaseInvoiceCalculator $purCalc,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PurchaseInvoiceCnbApplier $cnbApplier,
    ) {}

    /**
     * Spustí job. Volá worker, ne přímo UI (UI vytvoří job a vrátí, worker pak picknul).
     *
     * @param array<string,mixed> $params  z import_jobs.params:
     *   - include_clients: bool (default true)
     *   - include_issued: bool (default true)
     *   - include_received: bool (default true)
     *   - dry_run: bool (default false)
     */
    public function run(int $jobId): void
    {
        // Reload job uvnitř transakce — race-safe markRunning
        $job = $this->loadJob($jobId);
        if (!$this->jobs->markRunning($jobId)) {
            // Někdo jiný už picknul nebo byl cancelled
            return;
        }
        try {
            $params = $job['params'] ?? [];
            $supplierId = (int) $job['supplier_id'];
            $userId = (int) $job['created_by'];
            $dryRun = !empty($params['dry_run']);

            $incremental = !empty($params['incremental']);
            $downloadAttachments = !empty($params['download_attachments']);
            $bookmarkSince = $incremental ? $this->loadBookmark($supplierId) : null;

            $msg = 'Import zahájen' . ($dryRun ? ' (dry-run)' : '');
            if ($incremental && $bookmarkSince !== null) $msg .= ', incremental od ' . $bookmarkSince;
            if ($downloadAttachments) $msg .= ', s přílohami';
            $this->jobs->appendLog($jobId, $msg . '.');

            if (!empty($params['include_clients']) || ($params['include_clients'] ?? null) === null) {
                $this->importClients($jobId, $supplierId, $userId, $dryRun, $bookmarkSince);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_issued']) || ($params['include_issued'] ?? null) === null) {
                $this->importIssued($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
                $this->checkCancel($jobId);
                $this->importIssuedCorrections($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_received']) || ($params['include_received'] ?? null) === null) {
                $this->importReceived($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
            }

            // Mark completed + bookmark
            $this->jobs->appendLog($jobId, 'Import dokončen.');
            $this->jobs->markCompleted($jobId);
            $this->db->pdo()->prepare(
                'UPDATE supplier SET idoklad_last_imported_at = NOW() WHERE id = ?'
            )->execute([$supplierId]);
        } catch (CancelledException $e) {
            $this->jobs->appendLog($jobId, 'Import zrušen uživatelem.');
            $this->jobs->markCancelled($jobId);
        } catch (\Throwable $e) {
            $this->logger->error('iDoklad import failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            $this->jobs->appendLog($jobId, 'FAIL: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    private function loadJob(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Import job #{$jobId} nenalezen.");
        }
        if (!empty($row['params'])) {
            $row['params'] = json_decode((string) $row['params'], true);
        }
        return $row;
    }

    private function checkCancel(int $jobId): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            throw new CancelledException();
        }
    }

    /**
     * Import Contacts → clients. Dedup přes (supplier_id, idoklad_id).
     */
    private function importClients(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing contacts…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji kontakty z iDoklad' . ($bookmarkSince ? " (>{$bookmarkSince})" : '') . '…');

        // iDoklad podporuje filter `DateLastChange>=YYYY-MM-DD` pro incremental sync
        $query = $bookmarkSince !== null ? ['filter' => "DateLastChange>={$bookmarkSince}"] : [];

        $created = 0; $skipped = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'Contacts', $query) as $contact) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($contact['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $created++;
                continue;
            }

            // Create — map iDoklad Contact → clients schema
            try {
                $clientId = $this->createClientFromIdoklad($contact, $supplierId);
                $this->db->pdo()->prepare(
                    'UPDATE clients SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $clientId]);
                $created++;
            } catch (\Throwable $e) {
                $this->jobs->appendLog($jobId, "Kontakt {$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
        $this->jobs->appendLog($jobId, "Kontakty: vytvořeno {$created}, přeskočeno {$skipped} (z {$processed}).");
    }

    /**
     * Map iDoklad Contact → clients row + create.
     */
    private function createClientFromIdoklad(array $c, int $supplierId): int
    {
        $countryIso2 = strtoupper((string) ($c['Country']['Code'] ?? 'CZ'));
        $data = [
            'company_name' => (string) ($c['CompanyName'] ?: ($c['FirstName'] . ' ' . $c['Surname'] ?: 'iDoklad import')),
            'ic'           => (string) ($c['IdentificationNumber'] ?? '') ?: null,
            'dic'          => (string) ($c['VatIdentificationNumber'] ?? '') ?: null,
            'street'       => (string) ($c['Street'] ?? '—'),
            'city'         => (string) ($c['City'] ?? '—'),
            'zip'          => (string) ($c['PostalCode'] ?? '00000'),
            'country_iso2' => $countryIso2,
            'main_email'   => (string) ($c['Email'] ?? '') ?: 'unknown@import.local',
            'phone'        => (string) ($c['Phone'] ?? '') ?: null,
            'language'     => 'cs',
            'is_customer'  => true,
            'is_vendor'    => false,
        ];
        return $this->clients->create($data, $supplierId);
    }

    /**
     * Import IssuedInvoices → invoices. MVP mapping: header + items, status='draft'.
     * Dobropisy (IssuedInvoiceCorrections) jsou separátní endpoint a dělají se ve fázi 3.
     *
     * Note: faktury z iDoklad nemají project_id (oni nemají koncept projektů jako my)
     * — project_id = NULL. Uživatel může později ručně přiřadit.
     */
    private function importIssued(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing issued invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji vydané faktury z iDoklad…');

        $query = $bookmarkSince !== null ? ['filter' => "DateLastChange>={$bookmarkSince}"] : [];

        $created = 0; $skipped = 0; $failed = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'IssuedInvoices', $query) as $idoklad) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($idoklad['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $invoiceId = $this->createIssuedFromIdoklad($idoklad, $supplierId, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $invoiceId]);
                $this->invCalc->recompute($invoiceId);
                if ($downloadAttachments) {
                    $this->archiveIssuedPdf($supplierId, $invoiceId, $idokladId, $idoklad);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Vydaná #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Vydané faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    /**
     * Vytvoří jednu vydanou fakturu z iDoklad payloadu.
     */
    private function createIssuedFromIdoklad(array $i, int $supplierId, int $userId): int
    {
        // Resolve client by PartnerId → idoklad_id v clients
        $partnerId = (int) ($i['PartnerId'] ?? 0);
        $clientId = $this->resolveClientByIdoklad($partnerId, $supplierId);
        if ($clientId === null) {
            throw new \RuntimeException("Klient s iDoklad ID {$partnerId} nenalezen — nejdřív naimportuj kontakty.");
        }

        $invoiceType = $this->mapIssuedDocumentType((int) ($i['DocumentType'] ?? 0));

        $payload = [
            'invoice_type'    => $invoiceType,
            'client_id'       => $clientId,
            'issue_date'      => (string) ($i['DateOfIssue'] ?? date('Y-m-d')),
            'tax_date'        => $invoiceType === 'proforma' ? null : (string) ($i['DateOfTaxing'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
            'due_date'        => (string) ($i['DateOfMaturity'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
            'currency_id'     => $this->resolveCurrencyId((string) ($i['CurrencyCode'] ?? 'CZK'), $supplierId, isActive: true),
            'reverse_charge'  => false,
            'language'        => 'cs',
            'varsymbol'       => $this->sanitizeVarsymbol((string) ($i['VariableSymbol'] ?? $i['DocumentNumber'] ?? '')),
            'payment_method'  => 'bank_transfer',
        ];

        $invoiceId = $this->invoices->createDraft($payload, $userId);

        // Items
        $vatRates = $this->loadVatRateMap();
        $items = [];
        foreach (($i['Items'] ?? []) as $idx => $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            $vatRateId = $this->matchVatRateId($vatRates, $rate);
            $items[] = [
                'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                'quantity'               => (float) ($line['Amount'] ?? 1),
                'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['UnitPrice'] ?? 0),
                'vat_rate_id'            => $vatRateId,
                'order_index'            => $idx,
            ];
        }
        if (!empty($items)) {
            $this->invoices->replaceItems($invoiceId, $items);
        }
        return $invoiceId;
    }

    /**
     * Import ReceivedInvoices → purchase_invoices.
     *
     * Per fork bug fix: DateOfIssue často NULL pro přijaté, fallback DateOfAccountingEvent.
     */
    private function importReceived(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing received invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji přijaté faktury z iDoklad…');

        $query = $bookmarkSince !== null ? ['filter' => "DateLastChange>={$bookmarkSince}"] : [];

        $created = 0; $skipped = 0; $failed = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'ReceivedInvoices', $query) as $idoklad) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($idoklad['Id'] ?? 0);
            if ($idokladId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $purchaseId = $this->createReceivedFromIdoklad($idoklad, $supplierId, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE purchase_invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $purchaseId]);
                $this->purCalc->recompute($purchaseId);
                if ($downloadAttachments) {
                    $this->archiveReceivedPdf($supplierId, $purchaseId, $idokladId);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Přijatá #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Přijaté faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    /**
     * Vytvoří jednu přijatou fakturu z iDoklad payloadu.
     */
    private function createReceivedFromIdoklad(array $i, int $supplierId, int $userId): int
    {
        // Resolve vendor by PartnerId → idoklad_id v clients (with is_vendor flag)
        $partnerId = (int) ($i['PartnerId'] ?? 0);
        $vendorId = $this->resolveClientByIdoklad($partnerId, $supplierId);
        if ($vendorId === null) {
            throw new \RuntimeException("Dodavatel s iDoklad ID {$partnerId} nenalezen — nejdřív naimportuj kontakty.");
        }
        // Promote na vendor (might be already-imported customer)
        $this->clients->markAsVendor($vendorId);

        // Date fallback: DateOfIssue → DateOfAccountingEvent → today (per fork bug fix)
        $issueDate = (string) ($i['DateOfIssue'] ?? $i['DateOfAccountingEvent'] ?? '') ?: date('Y-m-d');
        $taxDate   = (string) ($i['DateOfTaxing'] ?? $issueDate);
        $dueDate   = (string) ($i['DateOfMaturity'] ?? $issueDate);

        $vatRates = $this->loadVatRateMap();
        $defaultVatRateId = $this->matchVatRateId($vatRates, 21.0) ?? $this->matchVatRateId($vatRates, 0.0) ?? 0;

        $items = [];
        foreach (($i['Items'] ?? []) as $idx => $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            $items[] = [
                'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                'quantity'               => (float) ($line['Amount'] ?? 1),
                'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['UnitPrice'] ?? 0),
                'vat_rate_id'            => $this->matchVatRateId($vatRates, $rate) ?? $defaultVatRateId,
                'order_index'            => $idx,
            ];
        }

        // Auto-detect reverse charge: vendor non-CZ + všechny items vat_rate=0
        // (typicky přijatá faktura z EU s reverse charge). Stejná heuristika jako AI extractor.
        $reverseCharge = $this->inferReverseChargeFromItems($vendorId, $items);

        $payload = [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $this->sanitizeVendorNumber((string) ($i['DocumentNumber'] ?? '')),
            'document_kind'         => 'invoice',
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate,
            'due_date'              => $dueDate,
            'received_at'           => date('Y-m-d'),
            'currency_id'           => $this->resolveCurrencyId((string) ($i['CurrencyCode'] ?? 'CZK'), $supplierId, isActive: false),
            'exchange_rate'         => isset($i['ExchangeRate']) ? (float) $i['ExchangeRate'] : null,
            'exchange_rate_source'  => 'manual',
            'reverse_charge'        => $reverseCharge,
            'language'              => 'cs',
            'items'                 => $items,
        ];

        // Dedup guard — re-import stejné faktury (typicky při opakovaném pullu z iDokladu)
        // by jinak hodil SQL 23000 duplicate key. Vrátíme existující ID.
        $existingId = $this->purchaseRepo->findIdByVendorInvoice(
            $supplierId, $vendorId,
            (string) $payload['vendor_invoice_number'],
            (string) $payload['issue_date'],
        );
        if ($existingId !== null) {
            return $existingId;
        }

        $id = $this->purchaseRepo->createDraft($payload, $userId, $supplierId);
        if (!empty($items)) {
            $this->purchaseRepo->replaceItems($id, $items);
        }
        // Auto-ČNB kurz pro non-CZK fakturu pokud iDoklad neobsahoval explicitní kurz
        $this->cnbApplier->applyIfMissing(
            $id,
            $supplierId,
            (string) ($i['CurrencyCode'] ?? 'CZK'),
            (string) ($payload['tax_date'] ?? $payload['issue_date'] ?? ''),
            $payload['exchange_rate'] ?? null,
        );
        return $id;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function resolveClientByIdoklad(int $partnerId, int $supplierId): ?int
    {
        if ($partnerId === 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $partnerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Lookup or auto-create currency. Pro vydané faktury (issued) je třeba is_active=1
     * (musíme mít bankovní účet); pro přijaté stačí is_active=0 (jen pro nákupní cyklus).
     */
    private function resolveCurrencyId(string $code, int $supplierId, bool $isActive): int
    {
        $code = strtoupper(trim($code)) ?: 'CZK';
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, ?, 0)'
        )->execute([$supplierId, $code, $code, $code, $code, $code, $isActive ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<int, float>  id → rate_percent
     */
    private function loadVatRateMap(): array
    {
        // vat_rates nemá is_active — platnost se řídí valid_from/valid_to (k dnešku).
        $rows = $this->db->pdo()->query(
            'SELECT id, rate_percent FROM vat_rates
              WHERE (valid_from IS NULL OR valid_from <= CURDATE())
                AND (valid_to   IS NULL OR valid_to   >= CURDATE())'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (float) $r['rate_percent'];
        }
        return $map;
    }

    private function matchVatRateId(array $vatRates, float $rate): ?int
    {
        foreach ($vatRates as $id => $r) {
            if (abs($r - $rate) < 0.01) return $id;
        }
        return null;
    }

    /**
     * Heuristika reverse charge (přenesená daňová povinnost):
     *   - vendor je v non-CZ zemi (EU/třetí země)
     *   - všechny items mají vat_rate = 0
     *
     * Stejná logika jako AiPdfExtractor::inferReverseCharge — duplikováno bez společného
     * helperu (kvůli rozdílným tvarům items + vat_rate_id mapy).
     */
    private function inferReverseChargeFromItems(int $vendorId, array $items): bool
    {
        if (empty($items)) return false;
        $vatRates = $this->loadVatRateMap();
        foreach ($items as $it) {
            $rateId = (int) ($it['vat_rate_id'] ?? 0);
            $ratePercent = $vatRates[$rateId] ?? null;
            if ($ratePercent !== null && (float) $ratePercent > 0.0) return false;
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT co.iso2 FROM clients c JOIN countries co ON co.id = c.country_id WHERE c.id = ?'
            );
            $stmt->execute([$vendorId]);
            $iso2 = (string) $stmt->fetchColumn();
            return $iso2 !== '' && $iso2 !== 'CZ';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * iDoklad DocumentType pro vydané faktury:
     *   0 = Regular invoice
     *   1 = Proforma invoice
     *   2 = Tax document for advance payment
     *   3 = Final invoice (po proforma)
     *   5 = Credit note (= IssuedInvoiceCorrection separátní endpoint)
     */
    private function mapIssuedDocumentType(int $docType): string
    {
        return match ($docType) {
            1       => 'proforma',
            5       => 'credit_note',
            default => 'invoice',
        };
    }

    /**
     * Sanitize varsymbol pro DB column (varchar 20, [A-Za-z0-9_-]).
     */
    private function sanitizeVarsymbol(string $vs): string
    {
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '', $vs) ?? '';
        if ($vs === '') return 'IDOKLAD-' . substr((string) random_int(1000, 9999), 0, 4);
        return substr($vs, 0, 20);
    }

    private function sanitizeVendorNumber(string $vn): string
    {
        $vn = trim($vn);
        if ($vn === '') $vn = 'IDOKLAD-import';
        $vn = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vn);
        return strlen($vn) > 50 ? substr($vn, 0, 50) : $vn;
    }

    /**
     * Import IssuedInvoiceCorrections (dobropisy k vystaveným fakturám).
     * Mají ParentDocumentId odkazující na původní fakturu (idoklad_id),
     * což mapujeme na invoices.parent_invoice_id (po lookup do clients/invoices
     * podle naší db-side idoklad_id).
     */
    private function importIssuedCorrections(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing credit notes…']);
        $this->jobs->appendLog($jobId, 'Stahuji dobropisy z iDoklad…');

        $query = $bookmarkSince !== null ? ['filter' => "DateLastChange>={$bookmarkSince}"] : [];
        $created = 0; $skipped = 0; $failed = 0;

        foreach ($this->idoklad->getAll($supplierId, 'IssuedInvoiceCorrections', $query) as $i) {
            $idokladId = (int) ($i['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup — dobropisy jsou v `invoices` tabulce s invoice_type='credit_note'
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                // Resolve parent invoice
                $parentIdokladId = (int) ($i['ParentDocumentId'] ?? 0);
                $parentInvoiceId = null;
                if ($parentIdokladId > 0) {
                    $s = $this->db->pdo()->prepare(
                        'SELECT id FROM invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
                    );
                    $s->execute([$supplierId, $parentIdokladId]);
                    $pid = $s->fetchColumn();
                    $parentInvoiceId = $pid !== false ? (int) $pid : null;
                }

                // Create as invoice_type='credit_note' + parent reference
                $partnerId = (int) ($i['PartnerId'] ?? 0);
                $clientId = $this->resolveClientByIdoklad($partnerId, $supplierId);
                if ($clientId === null) {
                    throw new \RuntimeException("Klient #{$partnerId} nenalezen — naimportuj nejdřív kontakty.");
                }

                $payload = [
                    'invoice_type'      => 'credit_note',
                    'parent_invoice_id' => $parentInvoiceId,
                    'client_id'         => $clientId,
                    'issue_date'        => (string) ($i['DateOfIssue'] ?? date('Y-m-d')),
                    'tax_date'          => (string) ($i['DateOfTaxing'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
                    'due_date'          => (string) ($i['DateOfMaturity'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
                    'currency_id'       => $this->resolveCurrencyId((string) ($i['CurrencyCode'] ?? 'CZK'), $supplierId, isActive: true),
                    'reverse_charge'    => false,
                    'language'          => 'cs',
                    'varsymbol'         => $this->sanitizeVarsymbol((string) ($i['VariableSymbol'] ?? $i['DocumentNumber'] ?? '')),
                    'payment_method'    => 'bank_transfer',
                ];
                $invoiceId = $this->invoices->createDraft($payload, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $invoiceId]);

                // Items
                $vatRates = $this->loadVatRateMap();
                $items = [];
                foreach (($i['Items'] ?? []) as $idx => $line) {
                    $rate = (float) ($line['VatRate'] ?? 0);
                    $items[] = [
                        'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                        'quantity'               => (float) ($line['Amount'] ?? 1),
                        'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                        'unit_price_without_vat' => (float) ($line['UnitPrice'] ?? 0),
                        'vat_rate_id'            => $this->matchVatRateId($vatRates, $rate),
                        'order_index'            => $idx,
                    ];
                }
                if (!empty($items)) {
                    $this->invoices->replaceItems($invoiceId, $items);
                }
                $this->invCalc->recompute($invoiceId);
                if ($downloadAttachments) {
                    $this->archiveIssuedPdf($supplierId, $invoiceId, $idokladId, $i);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Dobropis #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Dobropisy: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed}.");
    }

    /**
     * Stáhne rendered PDF od iDoklad a uloží do storage/invoices/supplier-{id}/.
     * Dedup přes SHA-256: pokud už existuje stejný soubor, jen reuse path.
     * Pro vydanou fakturu používáme separátní column imported_pdf_* aby se nepřepsal
     * náš vlastní rendered PDF (pdf_path).
     */
    private function archiveIssuedPdf(int $supplierId, int $invoiceId, int $idokladId, array $idoklad): void
    {
        $pdf = $this->idoklad->downloadIssuedPdf($supplierId, $idokladId);
        if ($pdf === null) return;

        $archiveRoot = (string) $this->config->get('invoice.import_archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/invoices-imported'
                : __DIR__ . '/../../../../storage/invoices-imported';
        }
        $tenantDir = $archiveRoot . DIRECTORY_SEPARATOR . 'supplier-' . $supplierId;
        if (!is_dir($tenantDir)) @mkdir($tenantDir, 0755, true);

        $sha = hash('sha256', $pdf);
        $size = strlen($pdf);
        $disk = substr($sha, 0, 16) . '.pdf';
        $diskPath = $tenantDir . DIRECTORY_SEPARATOR . $disk;
        if (!is_file($diskPath)) {
            @file_put_contents($diskPath, $pdf);
        }
        $relPath = 'supplier-' . $supplierId . '/' . $disk;
        $name = ($idoklad['DocumentNumber'] ?? 'invoice') . '.pdf';
        $this->db->pdo()->prepare(
            'UPDATE invoices SET imported_pdf_path = ?, imported_pdf_hash = ?,
                                  imported_pdf_size_bytes = ?, imported_pdf_original_name = ?
              WHERE id = ?'
        )->execute([$relPath, $sha, $size, $name, $invoiceId]);
    }

    /**
     * Stáhne první PDF přílohu pro přijatou fakturu (typically jedna od dodavatele).
     */
    private function archiveReceivedPdf(int $supplierId, int $purchaseInvoiceId, int $idokladInvoiceId): void
    {
        $attachments = $this->idoklad->listReceivedAttachments($supplierId, $idokladInvoiceId);
        // Filter PDFs only (iDoklad může mít víc příloh — obrázky, atd.)
        $pdfAttachments = array_filter(
            $attachments,
            fn ($a) => str_contains(strtolower((string) ($a['ContentType'] ?? '')), 'pdf')
                || str_ends_with(strtolower((string) ($a['FileName'] ?? '')), '.pdf'),
        );
        if (empty($pdfAttachments)) return;

        $first = reset($pdfAttachments);
        $attachmentId = (int) ($first['Id'] ?? 0);
        if ($attachmentId === 0) return;

        $pdf = $this->idoklad->downloadReceivedAttachment($supplierId, $attachmentId);
        if ($pdf === null) return;

        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/purchase-invoices'
                : __DIR__ . '/../../../../storage/purchase-invoices';
        }
        $tenantDir = $archiveRoot . DIRECTORY_SEPARATOR . 'supplier-' . $supplierId;
        if (!is_dir($tenantDir)) @mkdir($tenantDir, 0755, true);

        $sha = hash('sha256', $pdf);
        $size = strlen($pdf);
        $disk = substr($sha, 0, 16) . '.pdf';
        $diskPath = $tenantDir . DIRECTORY_SEPARATOR . $disk;
        if (!is_file($diskPath)) {
            @file_put_contents($diskPath, $pdf);
        }
        $relPath = 'supplier-' . $supplierId . '/' . $disk;
        $name = (string) ($first['FileName'] ?? 'invoice.pdf');
        $this->purchaseRepo->setPdfMetadata($purchaseInvoiceId, $supplierId, $relPath, $sha, $size, $name);
    }

    /**
     * Bookmark — vrátí ISO date posledního úspěšného importu pro tento tenant.
     * Použito jako filter DateLastChange>=… pro incremental sync.
     */
    private function loadBookmark(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT idoklad_last_imported_at FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) return null;
        // ISO 8601 → iDoklad chce YYYY-MM-DD
        return substr((string) $val, 0, 10);
    }
}

/**
 * Marker exception pro graceful cancel — worker break loop a markCancelled.
 */
final class CancelledException extends \RuntimeException {}
