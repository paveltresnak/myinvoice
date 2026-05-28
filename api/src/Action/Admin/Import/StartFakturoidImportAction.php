<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\FakturoidClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/imports/fakturoid/start
 *
 * Stejný pattern jako StartIdokladImportAction — vytvoří job + spawn worker.
 * Worker dispatches dle source='fakturoid' do FakturoidImportService.
 */
final class StartFakturoidImportAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly FakturoidClient $fakturoid,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $creds = $this->fakturoid->getCredentials($supplierId);
        if ($creds === null) {
            return Json::error($response, 'no_credentials',
                'Fakturoid credentials nejsou nastaveny.', 400);
        }

        // Ukliď zaseknuté joby (mrtvý worker), jinak by navždy blokovaly nový start.
        $this->jobs->reapStale($supplierId, 'fakturoid');

        foreach ($this->jobs->listForTenant($supplierId, 'fakturoid', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running',
                    "Fakturoid import už běží (job #{$existing['id']}).",
                    409, ['existing_job_id' => $existing['id']],
                );
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $params = [
            'include_clients'      => $body['include_clients']  ?? true,
            'include_issued'       => $body['include_issued']   ?? true,
            'include_received'     => $body['include_received'] ?? true,
            'incremental'          => !empty($body['incremental']),
            'download_attachments' => !empty($body['download_attachments']),
            'dry_run'              => !empty($body['dry_run']),
        ];

        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, 'fakturoid', $params, $userId);

        $this->spawnWorker($jobId);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.fakturoid_started', $userId, 'import_job', $jobId, $params,
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'params' => $params], 201);
    }

    private function spawnWorker(int $jobId): void
    {
        // Stejný ověřený mechanismus jako admin/cron-jobs (BackgroundProcess).
        // POZOR: root z Bootstrap::rootDir(), NE dirname(__DIR__, 4) — to z
        // api/src/Action/Admin/Import/ ukazovalo na .../api → cesta api/api/bin/…
        // neexistovala a worker se nikdy nespustil (job uvízl "queued").
        $rootDir = \MyInvoice\Bootstrap::rootDir();
        \MyInvoice\Service\BackgroundProcess::spawnPhp(
            $rootDir . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            \MyInvoice\Infrastructure\Config\RuntimePaths::log('import-worker.log'),
            $rootDir,
        );
    }
}
