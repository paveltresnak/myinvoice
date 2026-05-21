<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\VatClassificationRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * VAT classification codes CRUD:
 *   GET    /api/vat-classifications?direction=sale|purchase|both
 *   POST   /api/vat-classifications     — custom kód pro tenant
 *   PUT    /api/vat-classifications/{id} — jen tenant kódy (globální seed nelze)
 *   DELETE /api/vat-classifications/{id} — soft archived
 */
final class VatClassificationsAction
{
    public function __construct(
        private readonly VatClassificationRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $direction = in_array($q['direction'] ?? '', ['sale', 'purchase', 'both'], true)
            ? $q['direction'] : null;
        $includeArchived = !empty($q['include_archived']);
        return Json::ok($response, $this->repo->listForTenant($supplierId, $direction, $includeArchived));
    }

    public function create(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        try {
            $id = $this->repo->create($supplierId, $body);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', "Kód '{$body['code']}' už existuje.", 409);
            }
            return Json::error($response, 'create_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('vat_classification.created', $userId, 'vat_classification', $id, $body,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body, isUpdate: true);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        try {
            $this->repo->update($id, $supplierId, $body);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'cannot_edit_global', $e->getMessage(), 409);
        }
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->repo->delete($id, $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'cannot_delete_global', $e->getMessage(), 409);
        }
        return Json::ok($response, ['ok' => true, 'archived' => true]);
    }

    private function validate(array $body, bool $isUpdate = false): ?string
    {
        if (!$isUpdate) {
            $code = trim((string) ($body['code'] ?? ''));
            if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,8}$/', $code)) {
                return 'code: povinné, max 8 znaků [A-Za-z0-9_-].';
            }
        }
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '' || strlen($label) > 150) {
            return 'label povinný, max 150 znaků.';
        }
        if (!in_array($body['direction'] ?? 'both', ['sale', 'purchase', 'both'], true)) {
            return 'direction musí být sale|purchase|both.';
        }
        return null;
    }
}
