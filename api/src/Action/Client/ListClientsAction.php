<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListClientsAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $role = (string) ($q['role'] ?? 'all');
        if (!in_array($role, ['all', 'customers', 'vendors'], true)) {
            $role = 'all';
        }
        $filters = [
            'q'           => isset($q['q']) ? trim((string) $q['q']) : '',
            'archived'    => !empty($q['filter']['archived']),
            'role'        => $role,
            'supplier_id' => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
        ];
        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.clients_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));
        $sort = (string) ($q['sort'] ?? 'name');

        return Json::ok($response, $this->repo->list($filters, $page, $perPage, $sort));
    }
}
