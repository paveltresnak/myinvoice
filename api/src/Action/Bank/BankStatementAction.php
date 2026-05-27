<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\StatementScanner;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bank statement endpoints (M5b).
 *
 *   POST   /api/bank-statements/upload         multipart file=...
 *   GET    /api/bank-statements                list
 *   GET    /api/bank-statements/{id}           detail (+ transactions)
 *   POST   /api/bank-transactions/{id}/match   { invoice_id }  manual match
 *   POST   /api/bank-transactions/{id}/ignore  mark as ignored
 *   POST   /api/bank-transactions/{id}/unmatch reset back to unmatched
 */
final class BankStatementAction
{
    /** Absolutní tolerance shody částky ve stejné měně (měna faktury). */
    private const CANDIDATE_AMOUNT_TOLERANCE = 1.0;
    /** Relativní tolerance pro cross-currency shodu (CZK platba cizoměnové faktury) —
     *  banka si bere spread klidně ~2 % a kurz se za pár dní pohne, takže 4 % dává
     *  rezervu, aby se kandidáti reálně našli. */
    private const CANDIDATE_FX_TOLERANCE_PCT = 0.04;
    /** Okno ±N dní kolem data transakce (issue_date nebo due_date faktury). */
    private const CANDIDATE_DAY_WINDOW = 14;

    public function __construct(
        private readonly Connection $db,
        private readonly StatementImporter $importer,
        private readonly StatementMatcher $matcher,
        private readonly StatementScanner $scanner,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoiceRepository $invoices,
        private readonly GpcParser $parser,
        private readonly FinalFromProformaCreator $finalCreator,
        private readonly \MyInvoice\Repository\PurchaseInvoiceRepository $purchaseRepo,
        private readonly \MyInvoice\Service\Invoice\PurchaseInvoiceCalculator $purCalc,
    ) {}

    public function scan(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $root = (string) $this->config->get('bank_import.scan_root', '');
        if ($root === '' || !is_dir($root)) {
            return Json::error($response, 'config_missing', 'cfg.bank_import.scan_root není nastaveno nebo adresář neexistuje.', 400);
        }
        $summary = $this->scanner->scan($root);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.scanned', $user['id'] ?? null, null, null, $summary, $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $summary);
    }

    public function upload(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Soubor chybí.', 400);
        }

        // Limit velikosti — GPC výpisy bývají max stovky kB. 5 MiB je více než dost a chrání před DoS.
        $maxSize = 5 * 1024 * 1024;
        if (($file->getSize() ?? 0) > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 5 MiB).', 413);
        }

        // Whitelist přípon dle cfg.bank_import.allowed_exts
        $name = (string) $file->getClientFilename();
        $allowedExts = (array) $this->config->get('bank_import.allowed_exts', ['gpc', 'txt']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExts, true)) {
            return Json::error($response, 'invalid_extension', 'Nepovolená přípona souboru. Povolené: ' . implode(', ', $allowedExts), 400);
        }

        $content = (string) $file->getStream()->getContents();
        if (strlen($content) < 50) {
            return Json::error($response, 'empty_file', 'Soubor je prázdný.', 400);
        }

        // MIME check — GPC/ABO je plain text, odmítneme cokoliv binárního.
        // PHP 8.5+ deprecates finfo_close() (resource je auto-freed), proto ho neuvádíme.
        if (function_exists('finfo_buffer')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_buffer($finfo, $content);
                if ($mime !== '' && !str_starts_with($mime, 'text/') && $mime !== 'application/octet-stream') {
                    return Json::error($response, 'invalid_mime', 'Soubor není textový (detekováno: ' . $mime . ').', 400);
                }
            }
        }

        // MS-P2-1: parse hlavičku, ověř že account_number patří currencies aktuálního supplieru
        try {
            $parsed = $this->parser->parse($content);
        } catch (\Throwable $e) {
            return Json::error($response, 'parse_failed', 'Nelze parsovat: ' . $e->getMessage(), 400);
        }
        $accountNumber = (string) ($parsed['header']['account_number'] ?? '');
        if ($accountNumber !== '') {
            $sid = SupplierGuard::currentId($request);
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_number FROM currencies WHERE supplier_id = ? AND account_number IS NOT NULL'
            );
            $stmt->execute([$sid]);
            $found = false;
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $stored) {
                if (\MyInvoice\Service\Bank\AccountNumberNormalizer::equals((string) $stored, $accountNumber)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return Json::error(
                    $response,
                    'wrong_supplier_account',
                    "Bankovní účet $accountNumber není registrovaný u aktuálního supplier (Settings → měny → bankovní spojení).",
                    409
                );
            }
        }

        try {
            $r = $this->importer->import($content, $name, (int) ($user['id'] ?? 0));
        } catch (\Throwable $e) {
            return Json::error($response, 'parse_failed', 'Nelze parsovat: ' . $e->getMessage(), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.statement_imported', $user['id'] ?? null, 'bank_statement', $r['statement_id'], $r, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $r);
    }

    public function list(Request $request, Response $response): Response
    {
        // Multi-supplier scope: filter podle (account_number, bank_code) z currencies aktuálního supplier.
        // GPC zero-paduje účet (`0000001000000005`), currencies bez padding (`1000000005`) — porovnáváme
        // normalizované hodnoty (REGEXP_REPLACE non-digits + TRIM leading zeros).
        $sid = SupplierGuard::currentId($request);
        // account_label: vlastní pojmenování účtu z currencies.label (např. "CZK — Fio Bank")
        // přes scalar subselect (LIMIT 1 — sup. může mít jen 1 záznam per account_number+bank_code).
        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.id, bs.file_name, bs.account_number, bs.currency, bs.statement_date, bs.statement_number,
                    bs.prev_balance, bs.curr_balance, bs.transaction_count, bs.matched_count, bs.imported_at,
                    (bs.file_content IS NOT NULL) AS has_file,
                    (bs.pdf_content IS NOT NULL) AS has_pdf, bs.pdf_name,
                    (SELECT cur.label FROM currencies cur
                      WHERE cur.supplier_id = ?
                        AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                          = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                        AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                      LIMIT 1) AS account_label
               FROM bank_statements bs
              WHERE EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
              )
              ORDER BY bs.statement_date DESC, bs.id DESC
              LIMIT 200"
        );
        $stmt->execute([$sid, $sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['transaction_count'] = (int) $r['transaction_count'];
            $r['matched_count'] = (int) $r['matched_count'];
            $r['prev_balance'] = (float) $r['prev_balance'];
            $r['curr_balance'] = (float) $r['curr_balance'];
            $r['has_file'] = (bool) $r['has_file'];
            $r['has_pdf'] = (bool) $r['has_pdf'];
        }
        return Json::ok($response, $rows);
    }

    /**
     * DELETE /api/bank-statements/{id}
     *
     * Smaže výpis vč. transakcí (ON DELETE CASCADE) a payment_matches (CASCADE
     * přes bank_transactions). NEresetuje status faktur — ty zůstávají paid
     * (manuální cleanup u faktur, kterých se to týká, je doménou uživatele).
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $id <= 0) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        // RBAC — pouze admin. Účetní (accountant) může nahrávat a párovat,
        // ale destruktivní smazání výpisu vč. všech transakcí + party párování
        // nechte na adminovi (forensic integrity — uzávěrku DPH/KH je třeba
        // mít stabilní).
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin smí mazat výpisy.', 403);
        }

        // Supplier scope check — stejný pattern jako detail()
        $pdo = $this->db->pdo();
        $owned = $pdo->prepare(
            "SELECT bs.file_name FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $owned->execute([$id, $sid]);
        $fileName = $owned->fetchColumn();
        if ($fileName === false) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.statement_deleted', $user['id'] ?? null, 'bank_statement', $id, [
            'file_name' => $fileName,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * GET /api/bank-statements/{id}/download
     *
     * Vrátí originální obsah GPC souboru (uložený v bank_statements.file_content
     * od migrace 0045). Pro statementy importované před touto migrací vrací 404.
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $id <= 0) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.file_name, bs.file_content
               FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }
        if ($row['file_content'] === null || $row['file_content'] === '') {
            return Json::error($response, 'file_unavailable',
                'Originální soubor není k dispozici (výpis byl importován před verzí 4.1.0).', 410);
        }

        $fileName = (string) ($row['file_name'] ?: ('vypis-' . $id . '.gpc'));
        // ASCII-only filename pro Content-Disposition (RFC 6266 fallback) +
        // odstranění CRLF / quotes (header injection guard).
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $fileName) ?? $fileName;

        $response->getBody()->write((string) $row['file_content']);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=windows-1250')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) strlen((string) $row['file_content']))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Ověří, že výpis #$id patří aktuálnímu supplieru (přes account_number →
     * currencies.supplier_id, stejný normalizovaný match jako list/detail/download).
     */
    private function statementOwned(int $id, int $sid): bool
    {
        if ($id <= 0 || $sid <= 0) return false;
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )
              LIMIT 1"
        );
        $stmt->execute([$id, $sid]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * POST /api/bank-statements/{id}/pdf  (multipart file=...)
     *
     * Přiloží k existujícímu výpisu PDF verzi (např. oficiální PDF výpis z banky).
     * Ukládá se jako MEDIUMBLOB do bank_statements.pdf_content (stejně jako GPC).
     * Admin nebo účetní (write role).
     */
    public function uploadPdf(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if (!$this->statementOwned($id, $sid)) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Soubor chybí.', 400);
        }

        // PDF výpisy bývají do pár MB; 10 MiB je bezpečný strop (MEDIUMBLOB zvládá 16 MiB).
        $maxSize = 10 * 1024 * 1024;
        if (($file->getSize() ?? 0) > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 10 MiB).', 413);
        }

        $name = (string) $file->getClientFilename();
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            return Json::error($response, 'invalid_extension', 'Povolené je jen PDF.', 400);
        }

        $content = (string) $file->getStream()->getContents();
        // Magic bytes — PDF musí začínat "%PDF-" (případně s BOM/whitespace na začátku).
        if (!str_starts_with(ltrim($content, "\x00\x09\x0a\x0d\x20\xef\xbb\xbf"), '%PDF-')) {
            return Json::error($response, 'invalid_pdf', 'Soubor není platné PDF.', 400);
        }
        if (function_exists('finfo_buffer')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_buffer($finfo, $content);
                if ($mime !== '' && $mime !== 'application/pdf') {
                    return Json::error($response, 'invalid_mime', 'Soubor není PDF (detekováno: ' . $mime . ').', 400);
                }
            }
        }

        $hash = hash('sha256', $content);
        $this->db->pdo()->prepare(
            'UPDATE bank_statements
                SET pdf_content = ?, pdf_name = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_uploaded_at = NOW()
              WHERE id = ?'
        )->execute([$content, $name, $hash, strlen($content), $id]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.pdf_uploaded', $user['id'] ?? null, 'bank_statement', $id, [
            'pdf_name' => $name,
            'size'     => strlen($content),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['uploaded' => true, 'pdf_name' => $name]);
    }

    /**
     * GET /api/bank-statements/{id}/pdf
     *
     * Stáhne přiložené PDF (bank_statements.pdf_content). 404 pokud výpis nepatří
     * supplieru nebo PDF není nahrané.
     */
    public function downloadPdf(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if (!$this->statementOwned($id, $sid)) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $stmt = $this->db->pdo()->prepare('SELECT pdf_name, pdf_content, account_number FROM bank_statements WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['pdf_content'] === null || $row['pdf_content'] === '') {
            return Json::error($response, 'pdf_unavailable', 'K tomuto výpisu není nahrané PDF.', 404);
        }

        $fileName = (string) ($row['pdf_name'] ?: ('vypis-' . $id . '.pdf'));
        // Číslo účtu na začátek názvu, pokud tam ještě není — ať se stažené PDF
        // z různých účtů nepletou (např. „2026-02.pdf" → „1000000005-2026-02.pdf").
        // „Už obsahuje" testujeme i podle čistých číslic (formát s lomítkem/pomlčkou).
        $account = trim((string) ($row['account_number'] ?? ''));
        if ($account !== '') {
            $acctDigits = preg_replace('/\D/', '', $account) ?? '';
            $nameDigits = preg_replace('/\D/', '', $fileName) ?? '';
            $alreadyHas = str_contains($fileName, $account)
                || ($acctDigits !== '' && str_contains($nameDigits, $acctDigits));
            $acctSafe = preg_replace('/[^A-Za-z0-9_-]/', '', $account) ?? '';
            if (!$alreadyHas && $acctSafe !== '') {
                $fileName = $acctSafe . '-' . $fileName;
            }
        }
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $fileName) ?? $fileName;

        $response->getBody()->write((string) $row['pdf_content']);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) strlen((string) $row['pdf_content']))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * DELETE /api/bank-statements/{id}/pdf
     *
     * Smaže přiložené PDF (GPC i transakce zůstávají). Admin nebo účetní.
     */
    public function deletePdf(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if (!$this->statementOwned($id, $sid)) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $this->db->pdo()->prepare(
            'UPDATE bank_statements
                SET pdf_content = NULL, pdf_name = NULL, pdf_hash = NULL, pdf_size_bytes = NULL, pdf_uploaded_at = NULL
              WHERE id = ?'
        )->execute([$id]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.pdf_deleted', $user['id'] ?? null, 'bank_statement', $id, [], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        // Normalize porovnání account_number — viz `list()` komentář.
        // POZOR: explicit columns (ne `bs.*`) — file_content je MEDIUMBLOB se surovými
        // CP1250 bajty GPC souboru a Json::ok() na něj padá s "Malformed UTF-8" když
        // se to dostane do json_encode. Místo toho exposujeme jen `has_file` flag,
        // bajty se stahují přes /download endpoint.
        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.id, bs.file_name, bs.file_hash, bs.account_number, bs.bank_code,
                    bs.currency, bs.statement_number, bs.statement_date,
                    bs.prev_balance, bs.curr_balance, bs.credit_total, bs.debit_total,
                    bs.transaction_count, bs.matched_count,
                    bs.imported_at, bs.imported_by,
                    (bs.file_content IS NOT NULL) AS has_file,
                    (bs.pdf_content IS NOT NULL) AS has_pdf, bs.pdf_name,
                    (SELECT cur.label FROM currencies cur
                      WHERE cur.supplier_id = ?
                        AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                          = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                        AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                      LIMIT 1) AS account_label
               FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $stmt->execute([$sid, $id, $sid]);
        $s = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$s) return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);

        $txStmt = $this->db->pdo()->prepare(
            'SELECT bt.*, i.varsymbol AS matched_varsymbol, i.amount_to_pay AS matched_invoice_amount,
                    i.client_id, c.company_name AS matched_client_name,
                    (SELECT pm.purchase_invoice_id FROM payment_matches pm
                      WHERE pm.bank_transaction_id = bt.id ORDER BY pm.id LIMIT 1) AS matched_purchase_invoice_id
               FROM bank_transactions bt
          LEFT JOIN invoices i ON i.id = bt.matched_invoice_id
          LEFT JOIN clients c ON c.id = i.client_id
              WHERE bt.statement_id = ?
           ORDER BY bt.posted_at, bt.id'
        );
        $txStmt->execute([$id]);
        $transactions = $txStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($transactions as &$t) {
            $t['id'] = (int) $t['id'];
            $t['amount'] = (float) $t['amount'];
            $t['matched_invoice_id'] = $t['matched_invoice_id'] !== null ? (int) $t['matched_invoice_id'] : null;
            $t['matched_purchase_invoice_id'] = $t['matched_purchase_invoice_id'] !== null ? (int) $t['matched_purchase_invoice_id'] : null;
        }
        $s['id'] = (int) $s['id'];
        $s['has_file'] = (bool) ($s['has_file'] ?? false);
        $s['has_pdf'] = (bool) ($s['has_pdf'] ?? false);
        $s['transactions'] = $transactions;
        return Json::ok($response, $s);
    }

    /**
     * POST /api/bank-transactions/{id}/create-purchase-invoice
     *
     * Založí KONCEPT přijaté faktury (doklad o úhradě) z ODCHOZÍ bankovní transakce.
     * Spáruje dodavatele dle názvu protistrany, jinak ho založí (minimální). Předvyplní
     * fakturu (částka, datum, VS, měna, popis) a vrátí ID k otevření v editoru. Žádné
     * automatické párování ani placení — jen draft k revizi + nahrání PDF.
     */
    public function createPurchaseInvoice(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = (int) ($user['id'] ?? 0);
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT bt.amount, bt.posted_at, bt.variable_symbol, bt.counterparty_name,
                    bt.counterparty_account, bt.description, bs.account_number
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tx) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }
        if ((float) $tx['amount'] >= 0) {
            return Json::error($response, 'not_outgoing',
                'Přijatou fakturu lze založit jen z odchozí (záporné) platby.', 400);
        }

        $gross = round(abs((float) $tx['amount']), 2);
        $postedAt = (string) ($tx['posted_at'] ?? date('Y-m-d'));
        $name = trim((string) ($tx['counterparty_name'] ?? ''));
        [$currencyId, $currencyCode] = $this->resolveStatementCurrency($supplierId, (string) ($tx['account_number'] ?? ''));

        // Dodavatele NEzakládáme — musí existovat a uživatel ho vybral ve VendorPickeru
        // (vč. tlačítka „nový dodavatel"). Backend jen ověří, že patří tenantovi.
        $vendorId = (int) (((array) ($request->getParsedBody() ?? []))['vendor_id'] ?? 0);
        if ($vendorId <= 0) {
            return Json::error($response, 'vendor_required', 'Vyber dodavatele.', 400);
        }
        $vchk = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $vchk->execute([$vendorId]);
        if ((int) $vchk->fetchColumn() !== $supplierId) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }
        $pdo->prepare('UPDATE clients SET is_vendor = 1 WHERE id = ? AND is_vendor = 0')->execute([$vendorId]);

        // VS patří do pole varsymbol; vendor_invoice_number (povinné + součást unikátního
        // klíče uq_pi_vendor_invoice) nesmíme plnit VS — kolidovalo by. Dáme unikátní
        // placeholder BANK-{txId}, skutečné číslo dokladu doplní uživatel po nahrání PDF.
        $varsymbol = mb_substr(trim((string) ($tx['variable_symbol'] ?? '')), 0, 20) ?: null;
        $vendorInvoiceNumber = 'BANK-' . $txId;
        $descr = trim((string) ($tx['description'] ?? '')) ?: ($name ?: 'Platba z bankovního výpisu');

        // Už existuje koncept z této transakce? (opakované kliknutí) → přátelská hláška místo 500.
        $dupe = $pdo->prepare(
            'SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? LIMIT 1'
        );
        $dupe->execute([$supplierId, $vendorId, $vendorInvoiceNumber]);
        if ($existingId = (int) $dupe->fetchColumn()) {
            return Json::error($response, 'already_exists',
                'Z této transakce už koncept přijaté faktury existuje (#' . $existingId . ').', 409);
        }

        $piId = $this->purchaseRepo->createDraft([
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $vendorInvoiceNumber,
            'varsymbol'             => $varsymbol,
            'document_kind'         => 'invoice',
            'issue_date'            => $postedAt,
            'tax_date'              => $postedAt,
            'due_date'              => $postedAt,
            'received_at'           => $postedAt,
            'currency_id'           => $currencyId,
            'note_above_items'      => 'Předvyplněno z bankovního výpisu (tx #' . $txId . '). Zkontroluj DPH + nahraj PDF.',
        ], $userId, $supplierId);

        // Jedna položka v hrubé částce, sazba 0 % → total = uhrazená částka (po nahrání
        // PDF uživatel upraví rozpad DPH / položky).
        $zeroRateId = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->purchaseRepo->replaceItems($piId, [[
            'description'            => mb_substr($descr, 0, 255),
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => $gross,
            'vat_rate_id'            => $zeroRateId ?: null,
            'order_index'            => 0,
        ]]);
        $this->purCalc->recompute($piId);

        // Spáruj platbu na nově vzniklou přijatou fakturu (manuální, user-initiated klikem).
        // Draft fakturu neoznačujeme jako paid — to udělá uživatel po finalizaci; jen vazba.
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                 VALUES (?, ?, ?, ?, 'manual', ?)"
            )->execute([$supplierId, $txId, $piId, $gross, $userId ?: null]);
            $pdo->prepare(
                "UPDATE bank_transactions SET match_status = 'manual', matched_at = NOW(), matched_by = ? WHERE id = ?"
            )->execute([$userId ?: null, $txId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.purchase_draft_created', $userId, 'purchase_invoice', $piId, [
            'bank_transaction_id' => $txId, 'vendor_id' => $vendorId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'purchase_invoice_id' => $piId,
            'vendor_id'           => $vendorId,
            'currency'            => $currencyCode,
        ], 201);
    }

    /**
     * Měna dle účtu výpisu (normalizovaný match na currencies.account_number), fallback CZK.
     *
     * @return array{0:int, 1:string} [currency_id, code]
     */
    private function resolveStatementCurrency(int $supplierId, string $accountNumber): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, account_number FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([$supplierId]);
        $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($all as $c) {
            if (!empty($c['account_number'])
                && \MyInvoice\Service\Bank\AccountNumberNormalizer::equals((string) $c['account_number'], $accountNumber)) {
                return [(int) $c['id'], (string) $c['code']];
            }
        }
        foreach ($all as $c) {
            if ($c['code'] === 'CZK') return [(int) $c['id'], 'CZK'];
        }
        return $all ? [(int) $all[0]['id'], (string) $all[0]['code']] : [0, 'CZK'];
    }

    /**
     * Ověří, že bank_transaction patří aktuálnímu supplier-i (přes statement.account_number
     * → currencies.account_number/supplier_id). Vrací true / false; nevyhazuje výjimku,
     * caller pak vrátí 404.
     *
     * Sjednocený check pro všechny mutující ops na bank_transactions (match/ignore/unmatch).
     * Bez tohoto guardu by accountant z S1 mohl měnit transakce S2 (CWE-639 BOLA, security
     * report @andrejtomci #1).
     */
    private function txBelongsToCurrentSupplier(Request $request, int $txId): bool
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $txId <= 0) return false;
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?
                AND EXISTS (
                    SELECT 1 FROM currencies cur
                     WHERE cur.supplier_id = ?
                       AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                         = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                       AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $stmt->execute([$txId, $sid]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Návrh faktur ke spárování dle částky + data (±14 dní), když transakce nemá
     * VS nebo VS nesedí. Prohledá vystavené i přijaté faktury — kvůli dobropisům
     * může příchozí platba patřit k přijaté faktuře a naopak, takže směr (znaménko)
     * nefiltrujeme. Zahrnuje i zaplacené faktury (duplicitní/druhá platba, doplatek).
     *
     * Měna: cizoměnová faktura placená z CZK účtu se porovnává přes kurz faktury
     * (CZK = částka × kurz) s relativní tolerancí (bankovní spread + drift). Vrací
     * seznam k výběru vč. přepočtené částky; ruční zadání VS zůstává druhou možností.
     *
     * GET /api/bank-transactions/{id}/match-candidates → { candidates: [...] }
     */
    public function matchCandidates(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $sid = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        // Efektivní měna transakce = měna transakce, jinak měna výpisu (= měna účtu), jinak CZK.
        $stmt = $pdo->prepare(
            "SELECT bt.amount, bt.posted_at,
                    UPPER(COALESCE(NULLIF(bt.currency,''), NULLIF(bs.currency,''), 'CZK')) AS ccy
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?"
        );
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $txAmount = abs((float) ($tx['amount'] ?? 0));
        $posted   = (string) ($tx['posted_at'] ?? date('Y-m-d'));
        $txCcy    = (string) ($tx['ccy'] ?? 'CZK');
        if ($txAmount <= 0.0) {
            return Json::ok($response, ['candidates' => []]);
        }

        $win = self::CANDIDATE_DAY_WINDOW;
        // Otevřené i zaplacené doklady v okně ±N dní (vydané + přijaté). 'paid' zahrnujeme —
        // uživatel chce spárovat i s už zaplacenou fakturou (duplicitní/druhá platba, doplatek).
        // Částku NEfiltrujeme v SQL — kvůli cizí měně se porovnává přes kurz až v PHP.
        $issued = "SELECT 'invoice' AS mtype, i.id, i.varsymbol AS ref, i.amount_to_pay AS amount,
                          i.exchange_rate, i.issue_date, i.due_date, cur.code AS currency,
                          c.company_name AS party, i.status AS status
                     FROM invoices i
                     JOIN currencies cur ON cur.id = i.currency_id
                     LEFT JOIN clients c ON c.id = i.client_id
                    WHERE i.supplier_id = ?
                      AND i.status IN ('issued','sent','reminded','paid')
                      AND i.invoice_type IN ('invoice','proforma','credit_note')
                      AND (ABS(DATEDIFF(i.due_date, ?)) <= ? OR ABS(DATEDIFF(i.issue_date, ?)) <= ?)";

        $purchase = "SELECT 'purchase_invoice' AS mtype, p.id,
                            COALESCE(NULLIF(p.vendor_invoice_number,''), p.varsymbol) AS ref, p.amount_to_pay AS amount,
                            p.exchange_rate, p.issue_date, p.due_date, cur.code AS currency,
                            c.company_name AS party, p.status AS status
                       FROM purchase_invoices p
                       JOIN currencies cur ON cur.id = p.currency_id
                       LEFT JOIN clients c ON c.id = p.vendor_id
                      WHERE p.supplier_id = ?
                        AND p.status IN ('received','booked','paid')
                        AND (ABS(DATEDIFF(p.due_date, ?)) <= ? OR ABS(DATEDIFF(p.issue_date, ?)) <= ?)";

        $sql = "SELECT * FROM ($issued UNION ALL $purchase) cand ORDER BY cand.due_date DESC LIMIT 300";
        $branch = [$sid, $posted, $win, $posted, $win];
        $q = $pdo->prepare($sql);
        $q->execute(array_merge($branch, $branch));

        $absTol = self::CANDIDATE_AMOUNT_TOLERANCE;
        $pct    = self::CANDIDATE_FX_TOLERANCE_PCT;
        $local  = 'CZK';

        $candidates = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $invAmt = (float) $r['amount'];
            $invCcy = strtoupper((string) $r['currency']);
            $rate   = (float) ($r['exchange_rate'] ?: 0);
            if ($rate <= 0) {
                $rate = 1.0; // CZK faktura / chybějící kurz
            }

            $converted = null; // částka přepočtená do měny transakce (jen u cross-currency)
            if ($invCcy === $txCcy) {
                $expected = $invAmt;
                $tol = $absTol;
            } elseif ($txCcy === $local) {
                // Cizoměnová faktura placená v CZK → přepočet kurzem faktury (CZK = částka × kurz).
                $expected = $invAmt * $rate;
                $tol = max($absTol, $expected * $pct);
                $converted = $expected;
            } else {
                // Cizoměnový účet × jiná měna faktury — bez kurzu transakce nepřevedeme. Skip.
                continue;
            }

            $diff = abs($expected - $txAmount);
            if ($diff > $tol) {
                continue;
            }

            $candidates[] = [
                'type'               => $r['mtype'],
                'id'                 => (int) $r['id'],
                'ref'                => ($r['ref'] ?? '') !== '' ? (string) $r['ref'] : null,
                'amount'             => $invAmt,
                'currency'           => $invCcy,
                'converted_amount'   => $converted !== null ? round($converted, 2) : null,
                'converted_currency' => $converted !== null ? $txCcy : null,
                'issue_date'         => $r['issue_date'],
                'due_date'           => $r['due_date'],
                'party'              => $r['party'] !== null ? (string) $r['party'] : null,
                'paid'               => ($r['status'] ?? '') === 'paid',
                '_rel'               => $expected > 0 ? $diff / $expected : 0.0,
            ];
        }

        // Nejlepší relativní shoda první, pak nejnovější splatnost; cap 25.
        usort($candidates, static fn (array $a, array $b): int =>
            ($a['_rel'] <=> $b['_rel']) ?: strcmp((string) $b['due_date'], (string) $a['due_date']));
        $candidates = array_slice($candidates, 0, 25);
        foreach ($candidates as &$c) {
            unset($c['_rel']);
        }
        unset($c);

        return Json::ok($response, ['candidates' => $candidates]);
    }

    public function manualMatch(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $invoiceId = (int) ($body['invoice_id'] ?? 0);
        $purchaseInvoiceId = (int) ($body['purchase_invoice_id'] ?? 0);
        $varsymbol = trim((string) ($body['varsymbol'] ?? ''));

        // Purchase invoice match (přijatá faktura — outgoing payment)
        if ($purchaseInvoiceId > 0) {
            return $this->manualMatchPurchase($request, $response, $txId, $purchaseInvoiceId);
        }

        // Pokud uživatel poslal varsymbol místo invoice_id, najdi fakturu v supplier scope.
        // Fallback: zkus i přijaté faktury (purchase_invoices) — pro outgoing transakce.
        if ($invoiceId <= 0 && $varsymbol !== '') {
            $sid = SupplierGuard::currentId($request);
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
            );
            $stmt->execute([$sid, $varsymbol]);
            $invoiceId = (int) $stmt->fetchColumn();
            if ($invoiceId <= 0) {
                // Fallback: purchase_invoice match (přijatá faktura, my platíme dodavateli)
                $stmt = $this->db->pdo()->prepare(
                    // OR na vendor_invoice_number — viz StatementMatcher::matchPurchase
                    // (uživatel při manuálním matchi taky zadá VS dodavatele, ne naše PF-...).
                    'SELECT id FROM purchase_invoices
                       WHERE supplier_id = ?
                         AND (varsymbol = ? OR vendor_invoice_number = ?)
                       LIMIT 1'
                );
                $stmt->execute([$sid, $varsymbol, $varsymbol]);
                $pid = (int) $stmt->fetchColumn();
                if ($pid > 0) {
                    return $this->manualMatchPurchase($request, $response, $txId, $pid);
                }
                return Json::error($response, 'invoice_not_found',
                    "Faktura ani přijatá faktura s VS '$varsymbol' nenalezena.", 404);
            }
        }

        if ($invoiceId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí invoice_id nebo varsymbol.', 400);
        }

        // Faktura musí patřit aktuálnímu supplier (anti cross-supplier match)
        $invoice = $this->invoices->find($invoiceId);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'invoice_not_found', 'Faktura nenalezena.', 404);
        }
        if (
            in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)
            && !InvoiceAmountPolicy::canBeMarkedPaid($invoice)
        ) {
            return Json::error($response, 'invalid_amount', InvoiceAmountPolicy::NON_POSITIVE_MARK_PAID_MESSAGE, 409);
        }

        $pdo = $this->db->pdo();

        // Načti transakci pro posted_at (datum úhrady ze skutečnosti, ne dnes) + statement_id
        $tx = $pdo->prepare('SELECT posted_at, statement_id FROM bank_transactions WHERE id = ?');
        $tx->execute([$txId]);
        $txRow = $tx->fetch(\PDO::FETCH_ASSOC) ?: [];
        $postedAt = (string) ($txRow['posted_at'] ?? date('Y-m-d'));
        $statementId = (int) ($txRow['statement_id'] ?? 0);

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'manual', matched_at = NOW(), matched_by = ?
                  WHERE id = ?"
            )->execute([$invoiceId, $userId ?: null, $txId]);

            // Pokud faktura ještě není paid/cancelled, označ ji jako paid s datem z výpisu
            $finalDraftId = null;
            if (in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)) {
                $pdo->prepare(
                    "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                )->execute([$postedAt, $invoiceId]);

                // Zaplacená proforma → vytvoř DRAFT finální faktury (daňový doklad k záloze)
                if (($invoice['invoice_type'] ?? '') === 'proforma') {
                    $finalDraftId = $this->finalCreator->create($invoiceId, $userId ?: 0);
                }
            }

            // Recompute matched_count na výpisu (pro UI badge "12/14")
            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements
                        SET matched_count = (
                            SELECT COUNT(*) FROM bank_transactions
                             WHERE statement_id = ?
                               AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        )
                      WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'match_failed', 'Manuální párování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_manual_match', $userId ?: null, 'bank_transaction', $txId, [
            'invoice_id'     => $invoiceId,
            'paid_at'        => $postedAt,
            'final_draft_id' => $finalDraftId,
        ], $ip, $request->getHeaderLine('User-Agent'));
        if ($finalDraftId !== null) {
            $this->logger->log('proforma.final_issued', $userId ?: null, 'invoice', $invoiceId, [
                'final_invoice_id' => $finalDraftId,
                'trigger'          => 'bank_match_manual',
            ], $ip, $request->getHeaderLine('User-Agent'));
        }
        $result = ['matched' => true, 'paid_at' => $postedAt];
        if ($finalDraftId !== null) {
            $result['final_draft_id'] = $finalDraftId;
        }
        return Json::ok($response, $result);
    }

    /**
     * Manual match transakce ↔ purchase_invoice (přijatá faktura, outgoing payment).
     * Používá payment_matches table (N:N model), na rozdíl od vystavených které mají
     * 1:1 přes bank_transactions.matched_invoice_id.
     */
    private function manualMatchPurchase(Request $request, Response $response, int $txId, int $purchaseInvoiceId): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        // Validate purchase invoice belongs to tenant + is in payable status
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, status, COALESCE(amount_to_pay, total_with_vat, 0) AS amount_to_pay
               FROM purchase_invoices WHERE id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $pi = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$pi || (int) $pi['supplier_id'] !== $supplierId) {
            return Json::error($response, 'purchase_not_found', 'Přijatá faktura nenalezena.', 404);
        }
        // 'paid' povolujeme — kandidáti nabízejí i zaplacené faktury (duplicitní/druhá
        // platba, doplatek); transakci jen navážeme, paid_at nepřepisujeme (viz níže).
        // Mirror StatementMatcher::matchPurchase i vystavené faktury (manualMatch).
        $alreadyPaid = ($pi['status'] === 'paid');
        if (!in_array($pi['status'], ['received', 'booked', 'paid'], true)) {
            return Json::error($response, 'invalid_status',
                "Přijatou fakturu ve stavu '{$pi['status']}' nelze spárovat.", 409);
        }

        // Load transaction for amount + posted_at
        $tx = $pdo->prepare('SELECT posted_at, amount, statement_id FROM bank_transactions WHERE id = ?');
        $tx->execute([$txId]);
        $txRow = $tx->fetch(\PDO::FETCH_ASSOC) ?: [];
        $postedAt = (string) ($txRow['posted_at'] ?? date('Y-m-d'));
        $statementId = (int) ($txRow['statement_id'] ?? 0);
        $absAmount = abs((float) ($txRow['amount'] ?? 0));

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        $pdo->beginTransaction();
        try {
            // Mark purchase paid — jen pokud ještě není (ručně zaplacenou jen navážeme,
            // status/paid_at nepřepisujeme — respektujeme stav nastavený uživatelem).
            if (!$alreadyPaid) {
                $pdo->prepare(
                    "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                )->execute([$postedAt, $purchaseInvoiceId]);
            }

            // Insert payment_match row (N:N support pro splátky)
            $pdo->prepare(
                "INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                 VALUES (?, ?, ?, ?, 'manual', ?)"
            )->execute([$supplierId, $txId, $purchaseInvoiceId, $absAmount, $userId ?: null]);

            // Mark transakci jako manual (matched_invoice_id zůstane NULL — to je pro vystavené)
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET match_status = 'manual', matched_at = NOW(), matched_by = ?
                  WHERE id = ?"
            )->execute([$userId ?: null, $txId]);

            // Recompute statement counter
            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements SET matched_count = (
                        SELECT COUNT(*) FROM bank_transactions
                         WHERE statement_id = ? AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                    ) WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'match_failed', 'Párování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_manual_match_purchase', $userId ?: null, 'bank_transaction', $txId, [
            'purchase_invoice_id' => $purchaseInvoiceId,
            'paid_at'             => $postedAt,
            'amount'              => $absAmount,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'matched'             => true,
            'paid_at'             => $postedAt,
            'purchase_invoice_id' => $purchaseInvoiceId,
        ]);
    }

    public function unmatch(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT id, statement_id, matched_invoice_id, posted_at, match_status
               FROM bank_transactions WHERE id = ?'
        );
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tx) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $statementId = (int) $tx['statement_id'];
        $invoiceId = $tx['matched_invoice_id'] !== null ? (int) $tx['matched_invoice_id'] : 0;
        $postedAt = (string) ($tx['posted_at'] ?? '');

        // Supplier scope check — fakturu (pokud byla spárována) ověř proti aktuálnímu supplier.
        // Pokud transakce nebyla spárovaná (jen 'ignored'), ověř scope přes statement → currencies.
        if ($invoiceId > 0) {
            $invoice = $this->invoices->find($invoiceId);
            if (!SupplierGuard::owns($request, $invoice)) {
                return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
            }
        } else {
            $sid = SupplierGuard::currentId($request);
            $own = $pdo->prepare(
                "SELECT 1 FROM bank_statements bs
                  WHERE bs.id = ?
                    AND EXISTS (
                        SELECT 1 FROM currencies cur
                         WHERE cur.supplier_id = ?
                           AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                             = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                           AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                    )"
            );
            $own->execute([$statementId, $sid]);
            if (!$own->fetchColumn()) {
                return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
            }
        }

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = NULL,
                        match_status       = 'unmatched',
                        matched_at         = NULL,
                        matched_by         = NULL
                  WHERE id = ?"
            )->execute([$txId]);

            // Pokud byla faktura označena jako paid s paid_at = posted_at této transakce
            // a nemá jinou stále spárovanou transakci, vrať ji na 'issued' a smaž paid_at.
            // (Konzervativní heuristika — neměníme stav, který někdo nastavil ručně později.)
            if ($invoiceId > 0 && $postedAt !== '') {
                $other = $pdo->prepare(
                    "SELECT COUNT(*) FROM bank_transactions
                      WHERE matched_invoice_id = ?
                        AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        AND id <> ?"
                );
                $other->execute([$invoiceId, $txId]);
                $stillMatched = (int) $other->fetchColumn();
                if ($stillMatched === 0) {
                    $pdo->prepare(
                        "UPDATE invoices
                            SET status = 'issued', paid_at = NULL
                          WHERE id = ?
                            AND status = 'paid'
                            AND paid_at = ?"
                    )->execute([$invoiceId, $postedAt]);
                }
            }

            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements
                        SET matched_count = (
                            SELECT COUNT(*) FROM bank_transactions
                             WHERE statement_id = ?
                               AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        )
                      WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'unmatch_failed', 'Zrušení spárování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_unmatch', $userId ?: null, 'bank_transaction', $txId, [
            'previous_invoice_id' => $invoiceId ?: null,
            'previous_status'     => $tx['match_status'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['unmatched' => true]);
    }

    public function ignore(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $pdo = $this->db->pdo();
        // Načti previous state pro audit log (před UPDATE)
        $prev = $pdo->prepare(
            'SELECT statement_id, match_status, matched_invoice_id FROM bank_transactions WHERE id = ?'
        );
        $prev->execute([$txId]);
        $prevRow = $prev->fetch(\PDO::FETCH_ASSOC) ?: [];
        $statementId = (int) ($prevRow['statement_id'] ?? 0);
        $previousStatus = (string) ($prevRow['match_status'] ?? '');
        $previousInvoiceId = $prevRow['matched_invoice_id'] !== null ? (int) $prevRow['matched_invoice_id'] : null;

        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored' WHERE id = ?")->execute([$txId]);

        // Pokud byla transakce dříve matched (auto/manual), recompute count na výpisu
        if ($statementId > 0) {
            $pdo->prepare(
                "UPDATE bank_statements
                    SET matched_count = (
                        SELECT COUNT(*) FROM bank_transactions
                         WHERE statement_id = ?
                           AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                    )
                  WHERE id = ?"
            )->execute([$statementId, $statementId]);
        }

        // Audit log — destructive op musí být dohledatelná (forensic integrity).
        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_ignore', $userId ?: null, 'bank_transaction', $txId, [
            'previous_status'     => $previousStatus,
            'previous_invoice_id' => $previousInvoiceId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ignored' => true]);
    }

    /**
     * Přepároj všechny dosud nespárované transakce výpisu — užitečné poté, co
     * uživatel ex-post doplnil přijaté/vystavené faktury, které by se daly napárovat.
     *
     * Volá StatementMatcher::match() pro každou transakci ve stavu 'unmatched' nebo
     * 'auto_partial'. Stávající 'auto_exact', 'manual' a 'ignored' nejsou dotčeny.
     */
    public function rematch(Request $request, Response $response, array $args): Response
    {
        $statementId = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $statementId <= 0) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $pdo = $this->db->pdo();
        $owned = $pdo->prepare(
            "SELECT 1 FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $owned->execute([$statementId, $sid]);
        if (!$owned->fetchColumn()) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $txs = $pdo->prepare(
            "SELECT id FROM bank_transactions
              WHERE statement_id = ?
                AND match_status IN ('unmatched', 'auto_partial')"
        );
        $txs->execute([$statementId]);
        $txIds = $txs->fetchAll(\PDO::FETCH_COLUMN);

        $newlyMatched = 0;
        $newlyPartial = 0;
        $stillUnmatched = 0;
        foreach ($txIds as $txId) {
            $r = $this->matcher->match((int) $txId);
            $s = (string) ($r['status'] ?? 'unmatched');
            if ($s === 'auto_exact') $newlyMatched++;
            elseif ($s === 'auto_partial') $newlyPartial++;
            else $stillUnmatched++;
        }

        // Recompute matched_count na výpisu
        $pdo->prepare(
            "UPDATE bank_statements
                SET matched_count = (
                    SELECT COUNT(*) FROM bank_transactions
                     WHERE statement_id = ?
                       AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                )
              WHERE id = ?"
        )->execute([$statementId, $statementId]);

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.statement_rematch', $userId ?: null, 'bank_statement', $statementId, [
            'considered'       => count($txIds),
            'newly_matched'    => $newlyMatched,
            'newly_partial'    => $newlyPartial,
            'still_unmatched'  => $stillUnmatched,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'considered'      => count($txIds),
            'newly_matched'   => $newlyMatched,
            'newly_partial'   => $newlyPartial,
            'still_unmatched' => $stillUnmatched,
        ]);
    }
}
