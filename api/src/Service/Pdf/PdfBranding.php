<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Mail\SafeLogoPath;

/**
 * Sdílená brandingová logika pro PDF (faktura + výkaz víceprací):
 *   - resolveLogoPath: gate na email_branding_enabled, SafeLogoPath validace,
 *     preference SVG sidecaru (crisp), fallback PNG,
 *   - accentCss: override CSS přebarvující fialové akcenty na zvolenou barvu.
 *
 * Cíl: faktura i výkaz mají identické chování hlavičky (3 varianty: bez loga →
 * textový název, jen logo, logo + název) a stejné barevné branding změny.
 */
final class PdfBranding
{
    /**
     * Logo pro PDF — jen když má dodavatel zapnutý branding (email_branding_enabled).
     * Bez brandingu vrací null → šablona vykreslí textový brand-name fallback.
     * Preferuje SVG sidecar (vektor), pokud je mPDF-kompatibilní; jinak PNG.
     */
    public static function logoPath(array $supplier, int $supplierIdFallback = 0): ?string
    {
        if (empty($supplier['email_branding_enabled'])) {
            return null;
        }
        $logoPath = $supplier['logo_path'] ?? null;
        if (!$logoPath) {
            return null;
        }

        // SafeLogoPath: defense-in-depth proti podstrčenému logo_path (security #2).
        $supplierId = (int) ($supplier['id'] ?? $supplierIdFallback);
        $abs = SafeLogoPath::resolve((string) $logoPath, $supplierId);
        if ($abs === null) {
            return null;
        }

        // SVG sidecar preferujeme (crisp), pokud neobsahuje mPDF-problematické prvky.
        $svgSibling = preg_replace('/\.png$/i', '.svg', (string) $logoPath);
        if (is_string($svgSibling) && $svgSibling !== $logoPath) {
            $svgAbs = SafeLogoPath::resolve($svgSibling, $supplierId);
            if ($svgAbs !== null && self::svgIsMpdfCompatible($svgAbs)) {
                return $svgAbs;
            }
        }
        return $abs;
    }

    /** True = supplier má logo, které lze v PDF zobrazit (pro gate `logo_show_name`). */
    private static function svgIsMpdfCompatible(string $svgPath): bool
    {
        $svg = (string) @file_get_contents($svgPath);
        if ($svg === '') {
            return false;
        }
        $bad = '/<(?:clipPath|use|mask|linearGradient|radialGradient|pattern|filter)\b/i';
        return !preg_match($bad, $svg);
    }

    /**
     * Per-supplier accent override CSS — přebarví fialové akcenty (#3B2D83 + světlé
     * varianty/linky) na zvolenou barvu. Gated na email_branding_enabled + nedefaultní
     * hex. Vrací '' pokud branding vypnutý nebo defaultní barva (ta je už v base CSS).
     *
     * Selektory pokrývají fakturu i výkaz (přebytečné selektory u výkazu jsou no-op:
     * .head border, .brand-name, .doc-type, .wr-title/.wr-link platí pro oba).
     */
    public static function accentCss(array $supplier): string
    {
        if (empty($supplier['email_branding_enabled'])) {
            return '';
        }
        $color = AccentColor::normalize($supplier['email_accent_color'] ?? null);
        if ($color === null || $color === AccentColor::DEFAULT) {
            return '';
        }

        $bgSoft     = AccentColor::tint($color, 0.08);
        $lineSoft   = AccentColor::tint($color, 0.24);
        $lineMedium = AccentColor::tint($color, 0.28);
        $badgeBorder = AccentColor::tint($color, 0.30);

        return "\n/* ─── Branding override (per-supplier accent color) ─── */\n"
            . ".head { border-bottom-color: {$color}; }\n"
            . ".brand-name, .doc-type { color: {$color}; }\n"
            . ".parties h2, td.meta-label, .bank-label, .qr-box .qr-label { color: {$color}; }\n"
            . "table.items th { background: {$color}; }\n"
            . "table.totals-table tr.grand td { background: {$color}; }\n"
            . "table.totals-table tr.to-pay td { border-top-color: {$color}; color: {$color}; background: {$bgSoft}; }\n"
            . "table.totals-table tr.subtotal td { border-top-color: {$lineSoft}; }\n"
            . "table.czk-recap td.czk-recap-title, table.czk-recap tr.grand td { color: {$color}; }\n"
            . "table.czk-recap td.czk-recap-title { border-bottom-color: {$lineMedium}; }\n"
            . "table.czk-recap tr.subtotal td { border-top-color: {$lineSoft}; }\n"
            . "table.bank-frame { border-color: {$lineMedium}; }\n"
            . ".qr-box { border-color: {$lineSoft}; }\n"
            . ".isdoc-badge { color: {$color}; background: {$bgSoft}; border-color: {$badgeBorder}; }\n"
            . ".note { border-left-color: {$color}; }\n"
            . ".note.rc-note { border-left-color: #E8A547; }\n"
            . ".wr-title, .wr-link { color: {$color}; }\n";
    }
}
