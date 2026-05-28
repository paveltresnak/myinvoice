<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/purchase-invoices/{id}/pdf
 *
 * Smaže archivovaný PDF od dodavatele. Pdf_path / hash / size / original_name
 * se vyresetují na NULL. Soubor na disku se smaže POKUD ho nepoužívá jiná faktura
 * (stejný pdf_hash u jiné purchase_invoice — dedup případ).
 *
 * Stav faktury zachován — jen příloha pryč. Pro úplné storno použij transition→cancelled.
 */
final class DeletePurchaseInvoicePdfAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
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
        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if (empty($invoice['pdf_path'])) {
            return Json::error($response, 'no_pdf', 'Faktura nemá archivované PDF.', 404);
        }

        $hash = (string) $invoice['pdf_hash'];
        $relPath = (string) $invoice['pdf_path'];

        // Reset metadata na faktuře
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices
                SET pdf_path = NULL, pdf_hash = NULL, pdf_size_bytes = NULL,
                    pdf_original_name = NULL, pdf_uploaded_at = NULL
              WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $supplierId]);

        // Smazat fyzický soubor JEN POKUD ho už nepoužívá jiná faktura (dedup případ)
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM purchase_invoices WHERE pdf_hash = ? AND id != ?'
        );
        $stmt->execute([$hash, $id]);
        $stillUsed = (int) $stmt->fetchColumn();

        $fileDeleted = false;
        if ($stillUsed === 0) {
            $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
            if ($archiveRoot === '') {
                $uploads = (string) $this->config->get('storage.uploads_dir', '');
                $archiveRoot = $uploads !== '' ? dirname($uploads) . '/purchase-invoices'
                    : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
            }
            $fullPath = $archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $real = realpath($fullPath);
            $archiveRootReal = realpath($archiveRoot);
            // Defense-in-depth: ujisti se že file je uvnitř archive root před @unlink
            if ($real !== false && $archiveRootReal !== false) {
                $isWindows = DIRECTORY_SEPARATOR === '\\';
                $needle = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
                $haystack = $isWindows ? strtolower($real) : $real;
                if (str_starts_with($haystack, $needle) && is_file($real)) {
                    $fileDeleted = @unlink($real);
                }
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.pdf_deleted', $user['id'] ?? null, 'purchase_invoice', $id, [
            'pdf_hash' => $hash,
            'file_deleted' => $fileDeleted,
            'still_used_elsewhere' => $stillUsed,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok' => true,
            'file_deleted' => $fileDeleted,
            'still_used_by' => $stillUsed,
        ]);
    }
}
