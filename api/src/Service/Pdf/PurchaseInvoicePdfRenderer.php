<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Render přijaté faktury jako PDF (naše rekonstrukce).
 *
 * **Use case:** Když nemáme originální PDF od dodavatele (importované jen metadata,
 * nebo zadané ručně), generujeme vlastní PDF pro účetní archiv. Design = stejný
 * vzhled jako naše vystavené faktury (jednotný styl, branded).
 *
 * Layout:
 *   - Header: vendor (jako "supplier" v rekonstrukci) + "Rekonstrukce" badge
 *   - Parties: vendor (zleva) + naše firma jako odběratel (zprava)
 *   - Meta: issue/tax/due dates + currency
 *   - Items table
 *   - Totals (bez DPH / DPH / s DPH)
 *   - Footer s attribution + warning že originál je závazný
 */
final class PurchaseInvoicePdfRenderer
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Render do PDF binary string.
     */
    public function render(int $purchaseInvoiceId, int $supplierId): string
    {
        $invoice = $this->repo->find($purchaseInvoiceId, $supplierId);
        if ($invoice === null) {
            throw new \RuntimeException("Přijatá faktura #{$purchaseInvoiceId} nenalezena.");
        }
        $vendor = $this->loadVendor((int) $invoice['vendor_id']);
        $ourCompany = $this->loadOurCompany($supplierId);
        $items = $invoice['items'] ?? [];

        // Totals — preferuj sub-object, fallback na top-level columns
        $totals = $invoice['totals'] ?? [
            'without_vat' => $invoice['total_without_vat'] ?? 0,
            'vat'         => $invoice['total_vat'] ?? 0,
            'with_vat'    => $invoice['total_with_vat'] ?? 0,
        ];

        // Map items na shape co Twig očekává
        $itemsNorm = array_map(fn ($it) => [
            'description'            => $it['description'] ?? '',
            'quantity'               => (float) ($it['quantity'] ?? 1),
            'unit'                   => $it['unit'] ?? 'ks',
            'unit_price_without_vat' => (float) ($it['unit_price_without_vat'] ?? 0),
            'vat_rate'               => (float) ($it['vat_rate_snapshot'] ?? $it['vat_rate'] ?? 0),
            'total_without_vat'      => (float) ($it['total_without_vat'] ?? 0),
        ], $items);

        $locale = $invoice['language'] ?? 'cs';
        $docTypeLabel = $this->docTypeLabel($invoice['document_kind'] ?? 'invoice', $locale);
        $currency = $invoice['currency'] ?? 'CZK';

        $css = $this->loadCss();
        $body = $this->twig()->render('purchase-invoice.twig', [
            'invoice'        => $invoice,
            'vendor'         => $vendor,
            'our_company'    => $ourCompany,
            'items'          => $itemsNorm,
            'totals'         => $totals,
            'currency'       => $currency,
            'doc_type_label' => $docTypeLabel,
            'locale'         => $locale,
            'css'            => $css,
        ]);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 18,
            'default_font' => 'dejavusans',
            'tempDir' => \MyInvoice\Infrastructure\Config\RuntimePaths::storage('mpdf-temp'),
        ]);
        $mpdf->SetTitle(($docTypeLabel ?: 'Faktura') . ' ' . ($invoice['vendor_invoice_number'] ?? '#' . $invoice['id']));
        $mpdf->SetCreator('MyInvoice.cz');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    private function loadVendor(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id, c.company_name, c.street, c.city, c.zip, c.ic, c.dic,
                    c.main_email AS email, c.phone, COALESCE(cnt.iso2, 'CZ') AS country_iso2
               FROM clients c
          LEFT JOIN countries cnt ON cnt.id = c.country_id
              WHERE c.id = ?"
        );
        $stmt->execute([$vendorId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function loadOurCompany(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip, s.ic, s.dic,
                    s.email, s.phone, COALESCE(c.iso2, 'CZ') AS country_iso2
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function docTypeLabel(string $kind, string $locale): string
    {
        if ($locale === 'en') {
            return match ($kind) {
                'receipt'     => 'Receipt',
                'credit_note' => 'Credit note',
                'advance'     => 'Advance',
                default       => 'Invoice',
            };
        }
        return match ($kind) {
            'receipt'     => 'Přijatá účtenka',
            'credit_note' => 'Přijatý dobropis',
            'advance'     => 'Přijatá záloha',
            default       => 'Přijatá faktura',
        };
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([
                Bootstrap::rootDir() . '/api/templates/purchase-invoice',
            ]);
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'strict_variables' => false,
                'cache' => false,
            ]);
        }
        return $this->twig;
    }

    /**
     * Reuse existing invoice.css + dodá několik tříd specifických pro reconstruction.
     */
    private function loadCss(): string
    {
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $base = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        $extra = <<<CSS

/* ── PŘIJATÁ FAKTURA — rekonstrukce specific ── */
.reconstruction-badge {
    display: inline-block;
    background: #FEF3C7;
    color: #92400E;
    font-size: 7pt;
    font-weight: bold;
    padding: 1.2pt 5pt;
    border-radius: 8pt;
    margin-top: 2mm;
    letter-spacing: 0.5pt;
    text-transform: uppercase;
}
.meta-info {
    width: 100%;
    border-collapse: collapse;
    margin: 4mm 0 5mm;
    border-top: 0.5pt solid #E5E7EB;
    border-bottom: 0.5pt solid #E5E7EB;
}
.meta-info td {
    padding: 3mm 3mm;
    border-right: 0.5pt solid #E5E7EB;
    width: 25%;
    vertical-align: top;
}
.meta-info td:last-child { border-right: none; }
.meta-info .label {
    display: block;
    font-size: 7pt;
    color: #6B7280;
    text-transform: uppercase;
    letter-spacing: 0.5pt;
    margin-bottom: 1mm;
}
.meta-info .value {
    display: block;
    font-size: 10pt;
    font-weight: 500;
    color: #15131D;
}
.note-above, .note-below {
    margin: 3mm 0;
    padding: 3mm 4mm;
    background: #F9FAFB;
    border-left: 2pt solid #3B2D83;
    font-size: 9pt;
}
.rc-note {
    background: #FEF3C7;
    border-left: 3pt solid #F59E0B;
    padding: 3mm 4mm;
    margin: 3mm 0;
    font-size: 9pt;
    color: #92400E;
}
table.items td.empty {
    text-align: center;
    color: #9CA3AF;
    padding: 8mm;
    font-style: italic;
}
CSS;
        return $base . $extra;
    }
}
