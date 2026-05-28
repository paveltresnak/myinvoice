<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id}
 *
 * Politika mazání:
 *   - draft                              → smí kdokoliv s rolí ≥ accountant
 *   - issued / sent / cancelled / paid   → smí pouze admin (force-delete účetního dokladu)
 *   - readonly role                      → nikdy
 *
 * Cascade chování (DB úroveň, migrace 0015):
 *   FK invoices.parent_invoice_id má ON DELETE CASCADE — smazání rodiče
 *   automaticky odstraní navazující storno/dobropis (a jejich items, work_reports
 *   přes existující CASCADE směrem dolů). Bank pairing matched_invoice_id
 *   je SET NULL, takže transakce zůstane, jen ztratí pair.
 *
 * Strana effektů:
 *   1. PDF cache invalidace pro fakturu I všechny děti (DB cascade soubory neuklidí)
 *   2. SQL DELETE (cascade smaže items, work_reports, child invoices)
 *   3. StatsRecomputer pro klienta + projekt (revenue cache)
 *   4. ActivityLog: 'invoice.deleted' (draft) | 'invoice.force_deleted' (non-draft)
 *      s detaily o smazaných potomcích pro forenzní audit
 */
final class DeleteInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
        private readonly PdfArchiveService $pdfArchive,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly StatsRecomputer $stats,
        private readonly VarsymbolGenerator $varsymbol,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $role   = (string) ($user['role'] ?? '');
        $status = (string) $existing['status'];

        if ($role === 'readonly') {
            return Json::error($response, 'forbidden', 'Read-only role nemůže mazat.', 403);
        }
        if ($status !== 'draft' && $role !== 'admin') {
            return Json::error(
                $response,
                'admin_required',
                'Smazat vystavenou, odeslanou, zaplacenou nebo stornovanou fakturu může jen admin.',
                403,
            );
        }

        // Najdi všechny child doklady (storno, dobropis) — díky CASCADE se smažou
        // s parentem, ale chceme je zalogovat a invalidovat jejich PDF cache (DB to neudělá).
        $children = $this->db->pdo()->prepare(
            'SELECT id, invoice_type, varsymbol, status, issue_date FROM invoices WHERE parent_invoice_id = ?'
        );
        $children->execute([$id]);
        $childRows = $children->fetchAll(\PDO::FETCH_ASSOC);

        // 1. Invalidate aktivní PDF cache (parent + děti)
        $this->pdf->invalidate($id, 'invalidate_manual');
        foreach ($childRows as $child) {
            $this->pdf->invalidate((int) $child['id'], 'invalidate_manual');
        }

        // 1b. Smaž historii PDF + uživatelské přílohy (FYZICKÉ soubory na disku).
        // DB řádky v invoice_pdfs / invoice_attachments se cascade smažou v kroku 2,
        // ale archivní soubory v _archive/ a attachments/{invoiceId}/ by jinak zůstaly orphan.
        $supplierId = (int) ($existing['supplier_id'] ?? 0);
        $purgedPdfs = $this->pdfArchive->purgeFilesForInvoice($id);
        $purgedAtts = $supplierId > 0 ? $this->attachments->purgeFilesForInvoice($supplierId, $id) : 0;
        foreach ($childRows as $child) {
            $cid = (int) $child['id'];
            $this->pdfArchive->purgeFilesForInvoice($cid);
            if ($supplierId > 0) {
                $this->attachments->purgeFilesForInvoice($supplierId, $cid);
            }
        }

        // Zachyt stats závislosti PŘED delete (po delete už client_id/project_id nepřečteme)
        $clientId  = isset($existing['client_id'])  ? (int) $existing['client_id']  : null;
        $projectId = isset($existing['project_id']) && $existing['project_id'] ? (int) $existing['project_id'] : null;

        // 1c. Pokud je tato faktura "poslední" ve své counter scope (a stejně tak
        // její cascade-deleted credit_note potomci), uvolni counter — další vystavená
        // dostane stejné číslo. Drafty nemají counter-derived varsymbol; cancellation
        // nedostává varsymbol z counteru vůbec (IssueInvoiceAction).
        $counterReleased = [];
        if ($supplierId > 0 && $status !== 'draft') {
            $parentType = (string) ($existing['invoice_type'] ?? '');
            $parentVs   = (string) ($existing['varsymbol'] ?? '');
            if ($parentVs !== '' && in_array($parentType, ['invoice', 'proforma', 'credit_note'], true)) {
                $issueDate = !empty($existing['issue_date']) ? new \DateTimeImmutable($existing['issue_date']) : null;
                if ($this->varsymbol->releaseIfLatest($supplierId, $parentType, $parentVs, $issueDate, $clientId ?? 0)) {
                    $counterReleased[] = ['id' => $id, 'varsymbol' => $parentVs, 'type' => $parentType];
                }
            }
        }
        foreach ($childRows as $child) {
            $ctype = (string) ($child['invoice_type'] ?? '');
            $cvs   = (string) ($child['varsymbol'] ?? '');
            if ($cvs === '' || !in_array($ctype, ['invoice', 'proforma', 'credit_note'], true)) continue;
            $cdate = !empty($child['issue_date']) ? new \DateTimeImmutable($child['issue_date']) : null;
            if ($this->varsymbol->releaseIfLatest($supplierId, $ctype, $cvs, $cdate, $clientId ?? 0)) {
                $counterReleased[] = ['id' => (int) $child['id'], 'varsymbol' => $cvs, 'type' => $ctype];
            }
        }

        // 2. Vlastní delete (CASCADE smaže items, work_reports, child invoices,
        //    invoice_pdfs, invoice_attachments — vše nahoru na FK invoice_id)
        $this->repo->delete($id);

        // 3. Recompute revenue stats (po smazání issued/sent/paid se mění agregát)
        if ($clientId !== null) {
            $this->stats->recomputeForIds($clientId, $projectId);
        }

        // 4. Audit log — víc detailů pro force-delete než pro draft
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $eventName = ($status === 'draft') ? 'invoice.deleted' : 'invoice.force_deleted';
        $this->logger->log($eventName, $user['id'] ?? null, 'invoice', $id, [
            'varsymbol'           => $existing['varsymbol'] ?? null,
            'type'                => $existing['invoice_type'] ?? null,
            'status_before'       => $status,
            'total'               => $existing['total_with_vat'] ?? null,
            'currency'            => $existing['currency'] ?? null,
            'cascade_deleted_ids' => array_column($childRows, 'id'),
            'cascade_deleted'     => array_map(static fn ($c) => [
                'id'        => (int) $c['id'],
                'type'      => $c['invoice_type'],
                'varsymbol' => $c['varsymbol'],
                'status'    => $c['status'],
            ], $childRows),
            'counter_released'    => $counterReleased,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok'               => true,
            'cascade_deleted'  => count($childRows),
            'counter_released' => count($counterReleased),
        ]);
    }
}
