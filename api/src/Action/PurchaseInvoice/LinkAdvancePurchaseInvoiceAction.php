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
 * POST /api/purchase-invoices/{id}/link-advance  body: {advance_id}
 *
 * Propojí finální přijatou fakturu se zálohovou (advance), aby se náklad
 * nepočítal dvakrát (záloha + vyúčtovací faktura). Vazba se ukládá na finální
 * fakturu; spárovaná záloha pak vypadne z nákladů/CRM/daně z příjmů.
 *
 * Vrací aktualizovaný invoice payload (vč. linked_advance).
 */
final class LinkAdvancePurchaseInvoiceAction
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
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $advanceId = (int) ($body['advance_id'] ?? 0);
        if ($advanceId <= 0) {
            return Json::error($response, 'invalid_advance', 'Chybí advance_id.', 400);
        }

        try {
            $this->repo->linkAdvance($id, $advanceId, $supplierId);
        } catch (\Throwable $e) {
            return Json::error($response, 'link_failed', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.advance_linked', $user['id'] ?? null, 'purchase_invoice', $id, [
            'advance_id' => $advanceId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
