<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/purchase-invoices/{id}/link-advance
 *
 * Zruší propojení finální faktury se zálohou. advance_paid_amount ponecháme
 * (ruční korekce), jen odpojíme vazbu — spárovaná záloha se opět zařadí do nákladů
 * dle běžného pravidla.
 */
final class UnlinkAdvancePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
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

        $this->repo->unlinkAdvance($id, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.advance_unlinked', $user['id'] ?? null, 'purchase_invoice', $id, [
            'previous_advance_id' => $existing['advance_purchase_invoice_id'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
