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

/**
 * DELETE /api/purchase-invoices/{id}
 *
 * Smaže přijatou fakturu vč. items (ON DELETE CASCADE). Pouze draft lze smazat.
 * Vystavené / zaúčtované doklady jsou součástí auditní stopy — používá se cancel.
 */
final class DeletePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
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

        // Default: jen draft lze smazat. Force=1 (admin) povolí smazat received/booked
        // (paid/cancelled stále chráněné — auditní stopa).
        $force = (string) ($request->getQueryParams()['force'] ?? '') === '1';
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $allowedForce = ['received', 'booked'];
        if ($existing['status'] !== 'draft') {
            if (!($force && $isAdmin && in_array($existing['status'], $allowedForce, true))) {
                return Json::error(
                    $response,
                    'not_deletable',
                    'Lze smazat pouze koncepty (nebo received/booked s force=1 jako admin). Pro paid/cancelled použijte storno.',
                    409,
                );
            }
        }

        // Před DB delete uchovat info o PDF (k orphan cleanup)
        $pdfPath = (string) ($existing['pdf_path'] ?? '');
        $pdfHash = (string) ($existing['pdf_hash'] ?? '');

        $this->repo->delete($id, $supplierId);

        // Orphan PDF cleanup — pokud žádná jiná faktura tenanta nemá stejný hash,
        // smaž soubor (s realpath check pro path traversal).
        $pdfDeleted = false;
        if ($pdfPath !== '' && $pdfHash !== '') {
            $stillUsed = $this->repo->findIdByPdfHash($supplierId, $pdfHash);
            if ($stillUsed === null) {
                $pdfDeleted = $this->safeUnlinkPdf($supplierId, $pdfPath);
            }
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.deleted', $user['id'] ?? null, 'purchase_invoice', $id,
            [
                'varsymbol'   => $existing['varsymbol'] ?? null,
                'pdf_deleted' => $pdfDeleted,
                'pdf_hash'    => $pdfHash !== '' ? substr($pdfHash, 0, 12) . '…' : null,
            ],
            $ip, $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, ['ok' => true, 'pdf_deleted' => $pdfDeleted]);
    }

    /**
     * Smaže PDF soubor s realpath check vůči archive root (path traversal guard).
     */
    private function safeUnlinkPdf(int $supplierId, string $relativePath): bool
    {
        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $storageBase = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $storageBase !== ''
                ? dirname($storageBase) . '/purchase-invoices'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
        }
        $fullPath = $archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $archiveRootReal = realpath($archiveRoot);
        $fullPathReal = realpath($fullPath);
        if ($archiveRootReal === false || $fullPathReal === false || !is_file($fullPathReal)) {
            return false;
        }
        // Windows is case-insensitive, normalize obě strany na lowercase
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $haystack = ($isWindows ? strtolower($fullPathReal) : $fullPathReal);
        $needle   = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($haystack, $needle)) {
            return false; // mimo archive root — path traversal attempt, refuse
        }
        return @unlink($fullPathReal);
    }
}
