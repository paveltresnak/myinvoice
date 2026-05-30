<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Zafixuje ověřené hodnoty (Finanční správa / ČSSZ / VZP, k 2026-05). Změna těchto
 * konstant musí být vědomá — proto je hlídá test.
 */
final class TaxConstantsTest extends TestCase
{
    public function testVerified2025Values(): void
    {
        $c = TaxConstants::forYear(2025);
        self::assertSame(104592, $c['pausal_annual']['band1']);  // 12× 8 716
        self::assertSame(1676052, $c['tax_high_threshold']);     // 36× prům. mzda 46 557
        self::assertSame(0.55, $c['social_assessment_pct']);
        self::assertSame(0.50, $c['health_assessment_pct']);     // ← zdravotní 50 %, ne 55 %
        self::assertSame(195540, $c['social_min_base_main']);    // 35 % × 46 557 × 12
        self::assertSame(61476, $c['social_min_base_secondary']);
        self::assertSame(279342, $c['health_min_base']);         // 50 % × 46 557 × 12
        self::assertSame([15204, 22320, 27840], $c['child_credits']);
    }

    public function testVerified2026Values(): void
    {
        $c = TaxConstants::forYear(2026);
        self::assertSame(119808, $c['pausal_annual']['band1']);  // 12× 9 984
        self::assertSame(1762812, $c['tax_high_threshold']);     // 36× prům. mzda 48 967
        self::assertSame(0.50, $c['health_assessment_pct']);
        self::assertSame(235044, $c['social_min_base_main']);    // 40 % × 48 967 × 12
        self::assertSame(64644, $c['social_min_base_secondary']);
        self::assertSame(293802, $c['health_min_base']);         // 50 % × 48 967 × 12
    }

    public function testAvailableYearsAndFallback(): void
    {
        self::assertContains(2025, TaxConstants::availableYears());
        self::assertContains(2026, TaxConstants::availableYears());
        // Neznámý rok → fallback na 2026 (nejnovější).
        self::assertSame(2026, TaxConstants::forYear(9999)['year']);
    }
}
