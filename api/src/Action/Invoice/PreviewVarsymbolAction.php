<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/preview-varsymbol?type=invoice&issue_date=2026-05-06&client_id=7
 *
 * Vrátí, jaký bude příští varsymbol pro aktuálního dodavatele + (volitelně) klienta +
 * daný typ + datum, BEZ inkrementu counteru. Slouží pro náhled v editoru faktury —
 * uživatel hned vidí, jaké číslo dostane při vystavení (pokud nepřepíše ručně).
 *
 * Když `client_id` chybí nebo klient nemá vlastní template, použije se supplier-wide
 * řada. Když má vlastní formát, vrátí se jeho per-client counter.
 *
 * Response: { "varsymbol": "JD2026-01", "has_template": true }
 *           { "varsymbol": "", "has_template": false }   pokud chybí template
 */
final class PreviewVarsymbolAction
{
    public function __construct(
        private readonly VarsymbolGenerator $varsymbol,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }

        $params   = $request->getQueryParams();
        $type     = (string) ($params['type'] ?? 'invoice');
        $date     = (string) ($params['issue_date'] ?? date('Y-m-d'));
        $clientId = (int) ($params['client_id'] ?? 0);

        if (!in_array($type, ['invoice', 'proforma', 'credit_note'], true)) {
            return Json::error($response, 'invalid_type', 'Neplatný typ.', 400);
        }

        try {
            $for = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            $for = new \DateTimeImmutable('today');
        }

        $varsymbol = $this->varsymbol->preview($supplierId, $type, $for, $clientId);

        return Json::ok($response, [
            'varsymbol'    => $varsymbol,
            'has_template' => $varsymbol !== '',
        ]);
    }
}
