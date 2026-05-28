<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PDO;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Scan adresáře s PDF / ISDOC pro automatické vytváření přijatých faktur.
 *
 * Postup:
 *   1. Načti inbox_dir z config; pokud prázdné → vrať [skipped: 'inbox not configured'].
 *   2. Rekurzivně projdi adresář, filtruj přípony z allowed_exts.
 *   3. Per soubor: spočti SHA-256 obsahu.
 *   4. Pokud existuje purchase_invoice s tímto pdf_hash → skip (dedup).
 *   5. Pokud .isdoc → parsuj přímo IsdocParser.
 *      Pokud .pdf → PdfIsdocExtractor → pokud najde embedded ISDOC, parsuj; jinak skip
 *        (fáze 1 nepodporuje AI fallback — to dorazí v fázi 2c).
 *      Pokud .xml → zkus parsovat jako ISDOC (může to být payload bez PDF wrapping).
 *   6. Z parsovaných dat:
 *      - Najdi/vytvoř vendor (matchuj přes IČ; pokud chybí, vytvoř nový clients řádek s is_vendor=1).
 *      - Vytvoř purchase_invoice draft.
 *      - Insertni items + recompute totals.
 *      - Archivuj PDF (přesun do archive_storage, fill pdf_path/hash/size/original_name).
 *      - Volitelně přesuň source file do move_processed_to subdiru.
 *   7. Vrať souhrn { created: int, skipped: int, failed: int, details: [{file, status, reason, purchase_invoice_id?}] }.
 *
 * Security:
 *   - Realpath check: každý file musí být uvnitř configured inbox_dir (ochrana symlinks).
 *   - Max file size 20 MiB per soubor.
 *   - Max 500 souborů per run (proti DoS na large dirs).
 */
final class PurchaseInvoiceInboxScanner
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;
    private const MAX_FILES_PER_RUN = 500;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly PdfIsdocExtractor $pdfExtractor,
        private readonly IsdocParser $isdocParser,
        private readonly IsdocToPurchaseInvoiceMapper $mapper,
        private readonly AiPdfExtractor $aiExtractor,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     dry_run: bool,
     *     inbox_dir: string,
     *     details: list<array<string,mixed>>
     * }
     */
    /**
     * @param callable|null $progress Optional callback(array $event) fired for each
     *        per-file event. Events have shape:
     *          - ['phase' => 'start',  'file' => abs, 'index' => 1-based, 'total' => N]
     *          - ['phase' => 'result', 'file' => abs, 'status' => ..., 'reason' => ...]
     *        Použito v cron skriptu pro live progress výpis do konzole/logu.
     */
    public function scan(int $supplierId, int $userId, bool $dryRun = false, ?callable $progress = null): array
    {
        $inboxDir = (string) $this->config->get('purchase_invoice.inbox_dir', '');
        if ($inboxDir === '') {
            return $this->emptyResult($inboxDir, $dryRun, [['file' => '', 'status' => 'config_missing', 'reason' => 'purchase_invoice.inbox_dir není nastaveno v cfg.php']]);
        }

        $inboxReal = realpath($inboxDir);
        if ($inboxReal === false || !is_dir($inboxReal)) {
            // Diagnostika: PHP user (z Apache/IIS) nemusí mít přístup ke cestě.
            // Vrátíme všechny relevantní info aby user věděl, kde grantnout práva.
            $phpUser = function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
                : (getenv('USERNAME') ?: get_current_user() ?: 'unknown');
            $sapi = php_sapi_name();
            $exists = file_exists($inboxDir);
            $readable = $exists && is_readable($inboxDir);

            // Testuj postupně subdir-by-subdir kde se to láme (pomáhá najít chybějící práva)
            $segments = preg_split('@[\\\\/]+@', trim($inboxDir, "\\/"));
            $brokenAt = null;
            $build = (str_starts_with($inboxDir, '/') ? '/' : '');
            foreach ($segments ?: [] as $seg) {
                $build .= $seg . DIRECTORY_SEPARATOR;
                if (!file_exists($build)) {
                    $brokenAt = rtrim($build, DIRECTORY_SEPARATOR);
                    break;
                }
            }

            $reason = "Inbox adresář nelze otevřít z PHP procesu (SAPI: {$sapi}, user: {$phpUser}). ";
            if (!$exists) {
                $reason .= "Cesta neexistuje pro tohoto usera";
                if ($brokenAt !== null) $reason .= " — selhalo na: {$brokenAt}";
                $reason .= '. ';
            } elseif (!$readable) {
                $reason .= 'Cesta existuje, ale není čitelná. ';
            }
            $reason .= "Řešení (PowerShell jako Admin): " .
                "icacls \"{$inboxDir}\" /grant \"{$phpUser}:(OI)(CI)R\" /T " .
                "— NEBO přesuň složku pod webroot (C:\\inetpub\\wwwroot\\myinvoice.cz\\inbox).";

            return $this->emptyResult($inboxDir, $dryRun, [[
                'file' => $inboxDir,
                'status' => 'inbox_missing',
                'reason' => $reason,
            ]]);
        }

        $recursive = (bool) $this->config->get('purchase_invoice.inbox_recursive', true);
        $allowedExts = (array) $this->config->get('purchase_invoice.allowed_exts', ['pdf', 'isdoc', 'xml']);
        $allowedExts = array_map('strtolower', $allowedExts);

        $created = 0; $skipped = 0; $failed = 0;
        $details = [];

        $files = $this->listFiles($inboxReal, $recursive, $allowedExts);
        $totalFiles = count($files);
        // Helper closure — wrap detail push + fire progress callback (pokud existuje).
        // Tím se výpis posílá průběžně po každém souboru, ne až na konci.
        $emit = function (array $detail) use (&$details, $progress): void {
            $details[] = $detail;
            if ($progress !== null) {
                ($progress)(['phase' => 'result'] + $detail);
            }
        };
        foreach ($files as $idx => $absPath) {
            if ($progress !== null) {
                ($progress)([
                    'phase' => 'start',
                    'file'  => $absPath,
                    'index' => $idx + 1,
                    'total' => $totalFiles,
                ]);
            }
            if ($created + $skipped + $failed >= self::MAX_FILES_PER_RUN) {
                $emit(['file' => $absPath, 'status' => 'limit_reached', 'reason' => 'Maximální počet souborů per run dosažen']);
                break;
            }

            // Realpath check — file MUSÍ být uvnitř inboxReal.
            // POZOR: Windows je case-insensitive FS, ale realpath() vrací path s casing
            // dle prvního použití (může se lišit mezi inboxReal a per-file real).
            // Na Linuxu je FS case-sensitive — porovnáváme striktně.
            $real = realpath($absPath);
            if ($real === false) {
                $failed++;
                $emit(['file' => $absPath, 'status' => 'rejected', 'reason' => 'Nelze resolvovat realpath']);
                continue;
            }
            $isWindows = DIRECTORY_SEPARATOR === '\\';
            $needle    = ($isWindows ? strtolower($inboxReal) : $inboxReal) . DIRECTORY_SEPARATOR;
            $haystack  = $isWindows ? strtolower($real) : $real;
            if (!str_starts_with($haystack, $needle)) {
                $failed++;
                $emit(['file' => $absPath, 'status' => 'rejected', 'reason' => 'Path traversal']);
                continue;
            }

            $size = @filesize($real);
            if ($size === false || $size === 0) {
                $failed++;
                $emit(['file' => $real, 'status' => 'rejected', 'reason' => 'Prázdný nebo nečitelný']);
                continue;
            }
            if ($size > self::MAX_FILE_SIZE) {
                $failed++;
                $emit(['file' => $real, 'status' => 'rejected', 'reason' => 'Soubor větší než 20 MiB']);
                continue;
            }

            $sha = hash_file('sha256', $real);
            if ($sha === false) {
                $failed++;
                $emit(['file' => $real, 'status' => 'rejected', 'reason' => 'Nelze spočítat hash']);
                continue;
            }

            $existingId = $this->purchaseRepo->findIdByPdfHash($supplierId, $sha);
            if ($existingId !== null) {
                $skipped++;
                $emit(['file' => $real, 'status' => 'skipped', 'reason' => 'Již importováno', 'purchase_invoice_id' => $existingId]);
                continue;
            }

            $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            $isdocXml = $this->extractIsdocXml($real, $ext);

            // Pokud PDF nemá ISDOC, zkusíme AI fallback (jen pokud je tenant
            // nakonfigurovaný a NEni dryRun).
            if ($isdocXml === null && $ext === 'pdf' && !$dryRun && $this->isAiConfigured($supplierId)) {
                $pdfBytes = @file_get_contents($real);
                if ($pdfBytes !== false) {
                    $aiResult = $this->aiExtractor->extractAndCreate(
                        $supplierId, $userId, $pdfBytes, null, basename($real),
                    );
                    if (!empty($aiResult['ok']) && !empty($aiResult['purchase_invoice_id'])) {
                        $created++;
                        $emit([
                            'file'   => $real,
                            'status' => 'imported',
                            'reason' => 'AI extract',
                            'purchase_invoice_id' => $aiResult['purchase_invoice_id'],
                            'vendor_id'           => $aiResult['vendor_id'] ?? null,
                            'source'              => $aiResult['source'] ?? 'ai',
                        ]);
                        continue;
                    }
                    // AI selhalo — pokračujeme do skipped section níže s AI error msg
                    $emit([
                        'file'   => $real,
                        'status' => 'skipped',
                        'reason' => 'AI extrakce selhala: ' . ($aiResult['error'] ?? 'unknown'),
                    ]);
                    $skipped++;
                    continue;
                }
            }

            if ($isdocXml === null) {
                $skipped++;
                $emit([
                    'file'   => $real,
                    'status' => 'skipped',
                    'reason' => $ext === 'pdf'
                        ? 'PDF neobsahuje ISDOC. Pro AI extrakci nakonfiguruj Anthropic Claude v Externí integrace → AI.'
                        : 'Soubor nelze parsovat jako ISDOC',
                ]);
                continue;
            }

            try {
                $parsed = $this->isdocParser->parse($isdocXml);
                if (empty($parsed['invoices'])) {
                    $failed++;
                    $emit(['file' => $real, 'status' => 'failed', 'reason' => 'ISDOC neobsahuje fakturu']);
                    continue;
                }
            } catch (\Throwable $e) {
                $failed++;
                $emit(['file' => $real, 'status' => 'failed', 'reason' => 'ISDOC parser error: ' . $e->getMessage()]);
                continue;
            }

            // Fáze 2 — mapper aktivní. Pro každou ISDOC invoice v souboru (typicky 1)
            // vytvoříme draft purchase_invoice + uložíme PDF do archive_storage.
            if ($dryRun) {
                $skipped++;
                $emit([
                    'file'   => $real,
                    'status' => 'skipped',
                    'reason' => 'dry-run — nezapisuji do DB',
                    'isdoc_invoice_count' => count($parsed['invoices']),
                    'supplier_ic'         => $parsed['supplier_ic'] ?? null,
                ]);
                continue;
            }

            $createdInThisFile = 0;
            foreach ($parsed['invoices'] as $inv) {
                try {
                    $result = $this->mapper->map($inv, $supplierId, $userId);
                    // Archive PDF — uložení do storage + metadata (pdf_hash dedup)
                    if ($ext === 'pdf') {
                        $this->archivePdf($result['purchase_invoice_id'], $supplierId, $real, $sha, $size);
                    }
                    $created++;
                    $createdInThisFile++;
                    $emit([
                        'file'   => $real,
                        'status' => 'created',
                        'reason' => $result['vendor_created']
                            ? 'vytvořen vendor + draft přijaté faktury'
                            : 'draft přijaté faktury (vendor reuse)',
                        'purchase_invoice_id' => $result['purchase_invoice_id'],
                    ]);
                } catch (\InvalidArgumentException $e) {
                    $failed++;
                    $emit(['file' => $real, 'status' => 'rejected', 'reason' => $e->getMessage()]);
                } catch (\Throwable $e) {
                    $failed++;
                    $emit(['file' => $real, 'status' => 'failed', 'reason' => 'Mapper error: ' . $e->getMessage()]);
                }
            }
        }

        return [
            'created'   => $created,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'dry_run'   => $dryRun,
            'inbox_dir' => $inboxReal,
            'details'   => $details,
        ];
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $dir, bool $recursive, array $allowedExts): array
    {
        $out = [];
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            $entries = @scandir($current);
            if ($entries === false) continue;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = $current . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path)) {
                    if ($recursive) $stack[] = $path;
                    continue;
                }
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExts, true)) {
                    $out[] = $path;
                }
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Extrahuje ISDOC XML z PDF (přes embedded files) nebo načte přímo .isdoc / .xml.
     */
    private function extractIsdocXml(string $path, string $ext): ?string
    {
        if ($ext === 'pdf') {
            $bytes = @file_get_contents($path);
            if ($bytes === false) return null;
            return $this->pdfExtractor->extract($bytes);
        }
        if ($ext === 'isdoc' || $ext === 'xml') {
            $bytes = @file_get_contents($path);
            if ($bytes === false) return null;
            // Quick sanity check: musí obsahovat ISDOC namespace
            if (!str_contains($bytes, 'isdoc.cz/namespace')) return null;
            return $bytes;
        }
        return null;
    }

    /**
     * Zkopíruje PDF z inboxu do archive_storage (mimo webroot) a uloží metadata na fakturu.
     * Dedup: pokud už existuje soubor se stejným SHA-256 v archivu, jen reuse path.
     */
    private function archivePdf(int $purchaseInvoiceId, int $supplierId, string $sourcePath, string $sha256, int $size): void
    {
        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/purchase-invoices'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
        }
        $tenantDir = $archiveRoot . DIRECTORY_SEPARATOR . 'supplier-' . $supplierId;
        if (!is_dir($tenantDir)) @mkdir($tenantDir, 0755, true);

        $diskName = substr($sha256, 0, 16) . '.pdf';
        $diskPath = $tenantDir . DIRECTORY_SEPARATOR . $diskName;
        if (!is_file($diskPath)) {
            @copy($sourcePath, $diskPath);
        }

        $relPath = 'supplier-' . $supplierId . '/' . $diskName;
        $originalName = basename($sourcePath);
        $this->purchaseRepo->setPdfMetadata($purchaseInvoiceId, $supplierId, $relPath, $sha256, $size, $originalName);
    }

    private function emptyResult(string $inboxDir, bool $dryRun, array $details): array
    {
        return [
            'created'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'dry_run'   => $dryRun,
            'inbox_dir' => $inboxDir,
            'details'   => $details,
        ];
    }

    /**
     * Zda má tenant nakonfigurovanou Anthropic API key pro AI extract.
     *
     * Credentials uloženy v supplier.anthropic_api_key_enc (varbinary, encrypted).
     * Pokud sloupec neexistuje (legacy install před fází 2c), vrátí false.
     */
    private function isAiConfigured(int $supplierId): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM supplier WHERE id = ? AND anthropic_api_key_enc IS NOT NULL LIMIT 1'
            );
            $stmt->execute([$supplierId]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
