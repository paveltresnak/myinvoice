<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Tax\TaxOptimizer;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy daňového enginu. Očekávané hodnoty jsou ručně dopočtené ze
 * ZÁKONNÝCH konstant 2025 (ověřené dle Finanční správy / ČSSZ / VZP) — testy tak
 * zafixují jak výpočet, tak správnost konstant. Klíčové: zdravotní vyměřovací
 * základ je 50 % zisku (NE 55 % jako sociální).
 */
final class TaxOptimizerTest extends TestCase
{
    private TaxOptimizer $opt;
    /** @var array<string,mixed> */
    private array $c;

    protected function setUp(): void
    {
        $this->opt = new TaxOptimizer();
        $this->c = TaxConstants::forYear(2025);
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function profile(array $over = []): array
    {
        return $over + [
            'activity_rate' => 60, 'flat_tax_band' => 'band1', 'is_vat_payer' => false,
            'is_secondary' => false, 'spouse_credit' => false, 'children_count' => 0,
            'mortgage_interest' => 0, 'pension_contrib' => 0, 'life_insurance' => 0, 'donations' => 0,
        ];
    }

    public function testRegularBasic60Percent(): void
    {
        $r = $this->opt->compare($this->profile(), 1_200_000, $this->c)['regular'];
        self::assertSame(720000.0, $r['expenses']);     // 60 % paušál
        self::assertSame(480000.0, $r['tax_base']);     // 1,2M − 720k
        self::assertSame(41160.0, $r['income_tax']);    // 72 000 − 30 840 sleva
        self::assertSame(77088.0, $r['social']);        // max(264k, 195 540) × 29,2 %
        self::assertSame(37711.0, $r['health']);        // min. základ 279 342 × 13,5 %
        self::assertSame(155959.0, $r['total']);
        self::assertSame(1044041.0, $r['net_income']);  // příjem − odvody
        self::assertEqualsWithDelta(0.13, $r['effective_rate'], 0.0001);
    }

    /**
     * Klíčový regresní test opravy: zdravotní základ = 50 % zisku, sociální 55 %.
     * Při zisku, kde 50 % > min. základ, se musí použít 540 000 (50 %), ne 594 000 (55 %).
     */
    public function testHealthBaseIs50PercentNot55(): void
    {
        $r = $this->opt->compare($this->profile(['activity_rate' => 40, 'flat_tax_band' => 'none']), 1_800_000, $this->c)['regular'];
        self::assertSame(720000.0, $r['expenses']);     // min(40 %, strop 800k) → 720k
        self::assertSame(173448.0, $r['social']);       // 594 000 (55 %) × 29,2 %
        self::assertSame(72900.0, $r['health']);        // 540 000 (50 %) × 13,5 %  ← NE 80 190
    }

    /** Progresivní 23 % nad hranicí + strop výdajového paušálu. */
    public function testProgressiveRateAndExpenseCap(): void
    {
        $r = $this->opt->compare($this->profile(['activity_rate' => 40, 'flat_tax_band' => 'none']), 2_500_000, $this->c)['regular'];
        self::assertSame(800000.0, $r['expenses']);     // strop 40 % paušálu
        self::assertSame(1700000.0, $r['tax_base']);
        // 1 676 052 × 15 % + (1 700 000 − 1 676 052) × 23 % = 256 916
        self::assertSame(256916.0, $r['tax_gross']);
    }

    /** Daňový bonus na děti — income_tax může jít do mínusu. */
    public function testChildTaxBonusGoesNegative(): void
    {
        $r = $this->opt->compare($this->profile(['children_count' => 3]), 400_000, $this->c)['regular'];
        // daň 24 000 − sleva 30 840 = 0; − (15 204 + 22 320 + 27 840) = −65 364
        self::assertSame(-65364.0, $r['income_tax']);
        self::assertTrue($r['is_bonus']);
        self::assertSame(57098.0, $r['social']);        // min. základ 195 540 (zisk nízký)
    }

    /** Vedlejší činnost používá NIŽŠÍ minimální vyměřovací základ sociálního. */
    public function testSecondaryActivityUsesSecondaryMinBase(): void
    {
        $r = $this->opt->compare($this->profile(['is_secondary' => true]), 100_000, $this->c)['regular'];
        self::assertSame(17951.0, $r['social']);        // 61 476 × 29,2 % (ne 195 540)
    }

    /** Paušál: vyšší příjem než strop deklarovaného pásma → posun + doplatek. */
    public function testPausalBandUpgradeSurcharge(): void
    {
        $cmp = $this->opt->compare($this->profile(['activity_rate' => 40, 'flat_tax_band' => 'band1']), 1_200_000, $this->c);
        $p = $cmp['pausal'];
        self::assertTrue($p['applicable']);
        self::assertSame('band2', $p['effective']);     // band1 strop 1M < 1,2M → band2
        self::assertSame(200940.0, $p['total']);
        self::assertSame(96348.0, $p['surcharge']);     // 200 940 − 104 592
    }

    /** Plátce DPH nemůže do paušálního režimu → vítězí standardní. */
    public function testVatPayerHasNoPausal(): void
    {
        $cmp = $this->opt->compare($this->profile(['is_vat_payer' => true]), 800_000, $this->c);
        self::assertFalse($cmp['pausal']['applicable']);
        self::assertSame('vat_payer', $cmp['pausal']['reason']);
        self::assertSame('regular', $cmp['winner']);
        self::assertNull($cmp['delta_regular_minus_pausal']);
    }

    /** Vítěz a delta: pro malého OSVČ je paušál levnější. */
    public function testWinnerIsPausalForSmallTrader(): void
    {
        $cmp = $this->opt->compare($this->profile(), 1_200_000, $this->c);
        self::assertSame('pausal', $cmp['winner']);
        self::assertSame(104592.0, $cmp['pausal']['total']);
        self::assertSame(51367.0, $cmp['delta_regular_minus_pausal']); // 155 959 − 104 592
    }

    /** Predikce: run-rate, projekce a měsíc překročení 2M + rada odložit. */
    public function testPredictProjectionAndVatCrossing(): void
    {
        $pred = $this->opt->predict($this->profile(), 1_000_000, 6, $this->c);
        self::assertSame(2000000.0, $pred['projected']);   // 6 měsíců → ×2
        $vatLow = array_values(array_filter($pred['crossings'], fn ($x) => $x['key'] === 'vat_low'))[0];
        self::assertTrue($vatLow['will_cross']);
        self::assertSame(12, $vatLow['month']);
        self::assertNotNull($pred['defer_advice']);
    }
}
