<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderer pro Knihu DPH PDF (landscape A4).
 *
 * Layout:
 *   - Header: "Kniha DPH" + supplier info + "Za období: dd.mm.yyyy - dd.mm.yyyy"
 *   - Pro každou section: nadpis "15 ř.040 - PŘIJATÁ: Z tuzemska - sazba 21 %"
 *   - Tabulka 11 sloupců: Datum plnění | Zaúčtování | Doklad | Popis | Základ CZK | DPH CZK | Celkem CZK | Partner (DIČ) | Orig. číslo | Orig. datum | KH
 *   - CELKEM řádek za section
 *   - Disclaimer footer
 *
 * Drafty (status='draft') zvýrazněné světle šedým podbarvením + prefix "[KONCEPT] ".
 */
final class DphBookPdfRenderer
{
    private ?Environment $twig = null;

    /**
     * @param array<string,mixed> $data výstup DphBookBuilder->build()
     */
    public function render(array $data): string
    {
        $body = $this->twig()->render('dph_book.twig', $data);

        $rootDir = Bootstrap::rootDir();
        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',                        // Landscape — 11 sloupců se musí vejít
            'orientation'   => 'L',
            'margin_left'   => 8,
            'margin_right'  => 8,
            'margin_top'    => 12,
            'margin_bottom' => 12,
            'default_font'  => 'dejavusans',
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
        ]);
        $period = $data['period']['year'] . '-' . str_pad((string) $data['period']['month'], 2, '0', \STR_PAD_LEFT);
        $mpdf->SetTitle('Kniha DPH ' . $period);
        $mpdf->SetCreator('MyInvoice.cz');

        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([
                Bootstrap::rootDir() . '/api/templates/report',
            ]);
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'strict_variables' => false,
                'cache' => false,
            ]);
            // Twig filter pro CZK formátování
            $this->twig->addFilter(new \Twig\TwigFilter('cz_money', function ($v) {
                return number_format((float) $v, 2, ',', ' ');
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_date', function ($v) {
                if (!$v) return '';
                try {
                    return (new \DateTimeImmutable((string) $v))->format('d.m.Y');
                } catch (\Throwable) {
                    return '';
                }
            }));
        }
        return $this->twig;
    }
}
