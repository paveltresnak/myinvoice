<?php

declare(strict_types=1);

namespace MyInvoice\Action\Crm;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Crm\CrmAggregationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CRM dashboard endpoints.
 *
 *   GET /api/crm/overview                                — KPI cards (this month vs last vs YTD)
 *   GET /api/crm/monthly?months=12&currency=CZK          — chart data (12 měsíců breakdown)
 *   GET /api/crm/top-clients?months=12&limit=10          — top klienti by revenue
 *   GET /api/crm/top-vendors?months=12&limit=10          — top vendoři by costs
 *   POST /api/crm/recompute                              — manual trigger sp_recompute (admin)
 *
 * Permissions: všechny GET pro all roles, recompute jen admin.
 */
final class CrmDashboardAction
{
    public function __construct(
        private readonly CrmAggregationService $crm,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, $this->crm->overview($supplierId));
    }

    public function monthly(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $months = max(1, min(36, (int) ($q['months'] ?? 12)));
        $currency = isset($q['currency']) ? (string) $q['currency'] : null;
        return Json::ok($response, $this->crm->monthlyHistory($supplierId, $months, $currency));
    }

    public function topClients(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $months = max(1, min(36, (int) ($q['months'] ?? 12)));
        $limit  = max(1, min(50, (int) ($q['limit'] ?? 10)));
        $currency = isset($q['currency']) ? (string) $q['currency'] : null;
        return Json::ok($response, $this->crm->topClients($supplierId, $months, $limit, $currency));
    }

    public function topVendors(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $months = max(1, min(36, (int) ($q['months'] ?? 12)));
        $limit  = max(1, min(50, (int) ($q['limit'] ?? 10)));
        $currency = isset($q['currency']) ? (string) $q['currency'] : null;
        return Json::ok($response, $this->crm->topVendors($supplierId, $months, $limit, $currency));
    }

    public function agingReceivables(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, $this->crm->agingReceivables($supplierId));
    }

    public function agingPayables(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, $this->crm->agingPayables($supplierId));
    }

    public function dso(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $months = max(1, min(36, (int) ($request->getQueryParams()['months'] ?? 12)));
        return Json::ok($response, $this->crm->daysSalesOutstanding($supplierId, $months));
    }

    public function punctuality(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $months = max(1, min(36, (int) ($request->getQueryParams()['months'] ?? 12)));
        return Json::ok($response, $this->crm->paymentPunctuality($supplierId, $months));
    }

    public function concentration(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $months = max(1, min(36, (int) ($q['months'] ?? 12)));
        $currency = isset($q['currency']) ? (string) $q['currency'] : null;
        return Json::ok($response, $this->crm->clientConcentration($supplierId, $months, $currency));
    }

    public function expenseBreakdown(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $months = max(1, min(36, (int) ($q['months'] ?? 12)));
        $currency = isset($q['currency']) ? (string) $q['currency'] : null;
        return Json::ok($response, $this->crm->expenseBreakdown($supplierId, $months, $currency));
    }

    public function churnRisk(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $threshold = max(7, min(365, (int) ($q['days'] ?? 60)));
        $limit = max(1, min(100, (int) ($q['limit'] ?? 20)));
        return Json::ok($response, $this->crm->churnRisk($supplierId, $threshold, $limit));
    }

    public function recompute(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $start = microtime(true);
        $this->crm->recompute($supplierId);
        $elapsedMs = (int) ((microtime(true) - $start) * 1000);
        return Json::ok($response, ['ok' => true, 'elapsed_ms' => $elapsedMs]);
    }

    /** Action items widget — daily TODO list. */
    public function actionItems(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        return Json::ok($response, $this->crm->actionItems($supplierId, $userId));
    }

    /** Dismiss action item (day / week / forever / historical). */
    public function dismissActionItem(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId <= 0) {
            return Json::error($response, 'unauthorized', 'Unauthorized.', 401);
        }
        $body = (array) $request->getParsedBody();
        $itemType = (string) ($body['item_type'] ?? '');
        $mode = (string) ($body['mode'] ?? '');
        try {
            $this->crm->dismissActionItem($supplierId, $userId, $itemType, $mode);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_input', $e->getMessage(), 400);
        }
        return Json::ok($response, ['ok' => true]);
    }

    /** Restore (un-dismiss) action item. */
    public function restoreActionItem(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId <= 0) {
            return Json::error($response, 'unauthorized', 'Unauthorized.', 401);
        }
        $body = (array) $request->getParsedBody();
        $itemType = (string) ($body['item_type'] ?? '');
        $this->crm->restoreActionItem($supplierId, $userId, $itemType);
        return Json::ok($response, ['ok' => true]);
    }

    /** Cash flow forecast 4 týdny dopředu. */
    public function cashFlowForecast(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $weeks = max(1, min(12, (int) ($q['weeks'] ?? 4)));
        $currency = isset($q['currency']) ? (string) $q['currency'] : 'CZK';
        return Json::ok($response, $this->crm->cashFlowForecast($supplierId, $weeks, $currency));
    }

    /** Late payment risk score per klient. */
    public function lateRisk(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($q['limit'] ?? 10)));
        return Json::ok($response, $this->crm->lateRisk($supplierId, $limit));
    }

    /** Reminder effectiveness funnel. */
    public function reminderEffectiveness(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $months = max(1, min(36, (int) ($q['months'] ?? 12)));
        return Json::ok($response, $this->crm->reminderEffectiveness($supplierId, $months));
    }

    /** Invoice → paid time histogram. */
    public function paymentTimeHistogram(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $months = max(1, min(36, (int) ($q['months'] ?? 12)));
        return Json::ok($response, $this->crm->paymentTimeHistogram($supplierId, $months));
    }
}
