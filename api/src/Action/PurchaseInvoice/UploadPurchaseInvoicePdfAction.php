<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/purchase-invoices/{id}/pdf
 *
 * Upload originálního vendor PDF a archivace pod purchase_invoices.pdf_path.
 * Slouží k uložení originálu dokladu pro pozdější export / audit.
 *
 * Validace:
 *   - max 20 MiB
 *   - MIME musí být application/pdf (finfo_file, ne client Content-Type)
 *   - magic bytes %PDF- musí být na začátku
 *   - storage je mimo webroot v config storage.purchase_invoice.archive_storage
 *     (nebo fallback storage/purchase-invoices), filename = SHA-256[0:16].pdf
 *   - dedup: pokud už existuje faktura se stejným pdf_hash, vrátí 409 s odkazem
 */
final class UploadPurchaseInvoicePdfAction
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MiB
    private const PDF_MAGIC     = "%PDF-";

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Soubor nebyl odeslán (field name: file).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
        }

        // PSR-7 getSize() někdy vrací 0/null (Slim 4 chunked upload) — fallback na stream size.
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0) {
            $stream = $file->getStream();
            $size = (int) ($stream->getSize() ?? 0);
        }
        if ($size <= 0) {
            return Json::error($response, 'empty_file', 'Soubor je prázdný.', 400);
        }
        if ($size > self::MAX_FILE_SIZE) {
            return Json::error($response, 'file_too_large',
                'Soubor je příliš velký (max ' . (int) (self::MAX_FILE_SIZE / 1024 / 1024) . ' MiB).', 413);
        }

        // Connect storage dir (mimo webroot)
        $archiveDir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveDir === '') {
            // fallback: storage/purchase-invoices
            $storageBase = (string) $this->config->get('storage.uploads_dir', '');
            $archiveDir = $storageBase !== ''
                ? dirname($storageBase) . '/purchase-invoices'
                : __DIR__ . '/../../../../storage/purchase-invoices';
        }
        $tenantDir = $archiveDir . DIRECTORY_SEPARATOR . 'supplier-' . $supplierId;
        if (!is_dir($tenantDir) && !@mkdir($tenantDir, 0755, true) && !is_dir($tenantDir)) {
            return Json::error($response, 'storage_unavailable', 'Adresář archivu nelze vytvořit.', 500);
        }
        if (!is_writable($tenantDir)) {
            return Json::error($response, 'storage_not_writable', 'Adresář archivu není zapisovatelný.', 500);
        }

        // Přesun do temporary jména, validace, pak finální
        $tmpPath = $tenantDir . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(8)) . '.pdf';
        try {
            $file->moveTo($tmpPath);
        } catch (\Throwable $e) {
            return Json::error($response, 'move_failed', 'Nepodařilo se přesunout soubor.', 500);
        }

        // Magic bytes — musí začínat %PDF-
        $fh = @fopen($tmpPath, 'rb');
        if ($fh === false) {
            @unlink($tmpPath);
            return Json::error($response, 'read_failed', 'Nepodařilo se přečíst soubor.', 500);
        }
        $magic = fread($fh, 5);
        fclose($fh);
        if ($magic !== self::PDF_MAGIC) {
            @unlink($tmpPath);
            return Json::error($response, 'invalid_pdf', 'Soubor není platný PDF dokument.', 400);
        }

        // MIME sniffing — PHP 8.5 deprecated finfo_close() (objects are GC'd automatically).
        // Deprecation warning bohužel kontaminuje JSON response → response není validní JSON
        // a frontend parsing fails. Proto finfo_close NEvoláme.
        $detectedMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detectedMime = (string) finfo_file($finfo, $tmpPath);
                // unset($finfo) — explicit GC, neemituje deprecation
                unset($finfo);
            }
        }
        if ($detectedMime !== 'application/pdf') {
            @unlink($tmpPath);
            return Json::error($response, 'invalid_pdf', "Neplatný MIME ($detectedMime), očekáváno application/pdf.", 400);
        }

        // SHA-256 hash pro dedup
        $sha256 = hash_file('sha256', $tmpPath);
        if ($sha256 === false) {
            @unlink($tmpPath);
            return Json::error($response, 'hash_failed', 'Nepodařilo se spočítat hash.', 500);
        }

        // Dedup: existuje už faktura se stejným hash u tenanta?
        $existingByHash = $this->repo->findIdByPdfHash($supplierId, $sha256);
        if ($existingByHash !== null && (int) $existingByHash !== $id) {
            @unlink($tmpPath);
            return Json::error($response, 'pdf_already_archived',
                'Stejný PDF dokument už je archivován u faktury #' . $existingByHash, 409,
                ['existing_purchase_invoice_id' => $existingByHash]);
        }

        // Finální storage
        $diskName = substr($sha256, 0, 16) . '.pdf';
        $finalPath = $tenantDir . DIRECTORY_SEPARATOR . $diskName;
        if (!@rename($tmpPath, $finalPath)) {
            if (!@copy($tmpPath, $finalPath)) {
                @unlink($tmpPath);
                return Json::error($response, 'store_failed', 'Nepodařilo se uložit soubor.', 500);
            }
            @unlink($tmpPath);
        }

        // Authoritative size — z actual disk file (PSR-7 getSize() někdy vrací 0 v Slim 4)
        $diskSize = (int) @filesize($finalPath);
        if ($diskSize > 0) {
            $size = $diskSize;
        }

        // Persist metadata. Pdf_path je relativní k archive_storage root, aby byl portable
        // mezi instalacemi (pokud uživatel přesune storage, hodnota dál ukazuje na správný file).
        $relativePath = 'supplier-' . $supplierId . '/' . $diskName;
        $originalName = $this->sanitizeFilename((string) $file->getClientFilename());

        $this->repo->setPdfMetadata($id, $supplierId, $relativePath, $sha256, $size, $originalName);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.pdf_uploaded', $user['id'] ?? null, 'purchase_invoice', $id, [
            'sha256'       => $sha256,
            'size_bytes'   => $size,
            'original_name' => $originalName,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok'              => true,
            'pdf_path'        => $relativePath,
            'pdf_hash'        => $sha256,
            'pdf_size_bytes'  => $size,
            'pdf_original_name' => $originalName,
        ]);
    }

    private function sanitizeFilename(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/', '_', $name);
        $name = (string) preg_replace('/[\x00-\x1F"<>|*?:]/', '_', $name);
        $name = trim($name, ". _");
        if ($name === '') $name = 'invoice.pdf';
        if (strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $name = substr($base, 0, 190) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }
}
