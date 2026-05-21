<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}
 *
 * Update přijaté faktury. Standardně lze editovat pouze draft.
 * Admin může s `?force=1` upravit i nepředaný (received) — booked / paid / cancelled jsou immutable.
 */
final class UpdatePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly VatClassificationDefaulter $vatDefaulter,
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

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $isForce = !empty($request->getQueryParams()['force']);

        if ($existing['status'] !== 'draft') {
            // Force-update: admin smí upravit received nebo booked (s ?force=1).
            // paid/cancelled zůstávají immutable (financial integrity).
            if (!$isAdmin || !$isForce || in_array($existing['status'], ['paid', 'cancelled'], true)) {
                return Json::error($response, 'not_editable',
                    "Faktura ve stavu '{$existing['status']}' nelze upravit. Admin může upravit received/booked s ?force=1.",
                    409);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Vendor scope check — pokud se mění vendor, musí patřit tenantovi
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }
        if (empty($vendor['is_vendor'])) {
            $this->clients->markAsVendor((int) $vendor['id']);
        }

        // Auto-default VAT klasifikace pokud uživatel nezadal — na header i items.
        $this->applyVatClassificationDefaults($body);

        try {
            $this->repo->updateDraft($id, $body, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($existing['status'] !== 'draft') ? 'purchase_invoice.force_updated' : 'purchase_invoice.updated';
        $this->logger->log($action, $user['id'] ?? null, 'purchase_invoice', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    /**
     * Auto-default vat_classification_code podle vat_rate na řádcích a header.
     * Aplikuje se jen pokud user nezadal (NULL nebo prázdný string).
     */
    private function applyVatClassificationDefaults(array &$body): void
    {
        $vatRates = $this->repo->vatRateMap();  // id → rate_percent
        $reverseCharge = !empty($body['reverse_charge']);

        // Items first — needed pro header dominantní sazby
        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as &$item) {
                if (!empty($item['vat_classification_code'])) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForPurchase($rate, $reverseCharge);
            }
            unset($item);
        }

        // Header default
        if (empty($body['vat_classification_code']) && !empty($body['items'])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100)];
            }, (array) $body['items']);
            $body['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                $itemsWithTotals,
                (bool) ($body['reverse_charge'] ?? false),
                'purchase',
            );
        }
    }
}
