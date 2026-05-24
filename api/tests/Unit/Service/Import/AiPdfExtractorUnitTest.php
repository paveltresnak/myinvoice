<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure-logic testy pro privátní helpery v AiPdfExtractoru.
 *
 * Pokrývá:
 *   - detectWeakExtraction — kdy spustit auto-upgrade na Sonnet (vendor=tenant /
 *     katastrofální items mismatch >50 %)
 *   - maybeFlagTotalsMismatch — signed sum (sleva s mínusem, dobropisy se zápornou
 *     qty), žádný `total_with_vat / 1.21` fallback (multi-rate false positive)
 *   - applyRoundingFromAiTotal — Zoner case 84092.58 vs "K úhradě" 84093 = 0.42 rounding
 *
 * Závislosti AiPdfExtractoru jsou mockované přes PHPUnit createMock — testy
 * neběží proti DB / API.
 */
#[AllowMockObjectsWithoutExpectations]
final class AiPdfExtractorUnitTest extends TestCase
{
    private PurchaseInvoiceRepository $repo;
    private AiPdfExtractor $extractor;

    protected function setUp(): void
    {
        // Mock všechny závislosti — privátní metody, které testujeme, používají jen
        // některé z nich. Pro pure-logic checky (detectWeakExtraction) žádné nepotřebujeme,
        // pro maybeFlagTotalsMismatch / applyRoundingFromAiTotal jen $repo.
        $this->repo = $this->createMock(PurchaseInvoiceRepository::class);

        $this->extractor = new AiPdfExtractor(
            $this->createMock(Connection::class),
            $this->createMock(AnthropicClient::class),
            $this->createMock(ClientResolver::class),
            $this->repo,
            $this->createMock(PurchaseInvoiceCalculator::class),
            $this->createMock(PdfIsdocExtractor::class),
            $this->createMock(IsdocParser::class),
            $this->createMock(IsdocToPurchaseInvoiceMapper::class),
            $this->createMock(Config::class),
            $this->createMock(CnbExchangeRateClient::class),
            new NullLogger(),
        );
    }

    // ── detectWeakExtraction ────────────────────────────────────────────────

    public function testDetectWeak_clean_extraction_returns_null(): void
    {
        $data = [
            'vendor'   => ['ic' => '19774290'],
            'customer' => ['ic' => '21370362'],
            'total_without_vat' => 4113.29,
            'items' => [
                ['quantity' => 1, 'unit_price_without_vat' => 4113.29],
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_vendor_equals_tenant_no_customer_triggers(): void
    {
        $data = [
            'vendor'   => ['ic' => '21370362'], // = tenant
            'customer' => ['ic' => null],
            'total_without_vat' => 5000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 5000]],
        ];
        $this->assertSame('vendor_is_tenant_no_swap_target',
            $this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_vendor_equals_tenant_with_customer_handles_swap(): void
    {
        // Když customer má jiné IČ než tenant, swap-back proběhne normální cestou
        // → není to weakness, nereaguj.
        $data = [
            'vendor'   => ['ic' => '21370362'], // = tenant
            'customer' => ['ic' => '19774290'], // jiný IČ → swap-back funguje
            'total_without_vat' => 5000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 5000]],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_catastrophic_mismatch_triggers(): void
    {
        // NC Auto pattern: AI total 5317, items sum 31057 (6x víc)
        $data = [
            'vendor'   => ['ic' => '19774290'],
            'customer' => ['ic' => '21370362'],
            'total_without_vat' => 5317.34,
            'items' => [
                ['quantity' => 1, 'unit_price_without_vat' => 239],
                ['quantity' => 14, 'unit_price_without_vat' => 1980], // 27720 halucinace
                ['quantity' => 1, 'unit_price_without_vat' => 3098.34],
            ],
        ];
        $this->assertSame('catastrophic_items_mismatch',
            $this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_discount_sign_under_threshold_does_not_trigger(): void
    {
        // Zoner pattern PŘED sleva fix: sleva kladná, items=84942, AI=69498 → 22 %.
        // Pod 50 % prahem → není to weakness, jen warning.
        $data = [
            'vendor'   => ['ic' => '49437381'],
            'customer' => ['ic' => '21370362'],
            'total_without_vat' => 69498.35,
            'items' => [
                ['quantity' => 12, 'unit_price_without_vat' => 5709],
                ['quantity' => 12, 'unit_price_without_vat' => 726],
                ['quantity' => 12, 'unit_price_without_vat' => 643.50], // chybí mínus
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_skips_check2_when_ai_total_without_vat_missing(): void
    {
        // Bez total_without_vat NESPOUŠTÍME items vs total kontrolu (multi-rate
        // by jinak vyžadoval `total_with_vat / 1.21`, což u 21/12/0 % mixu nesedí).
        $data = [
            'vendor'   => ['ic' => '19774290'],
            'customer' => ['ic' => '21370362'],
            'total_with_vat' => 6433.98, // jen s DPH, bez DPH chybí
            'items' => [
                ['quantity' => 14, 'unit_price_without_vat' => 1980], // halucinace
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_no_tenant_ic_skips_vendor_check(): void
    {
        // Když nemáme tenant IC (např. supplier bez IČ — zahraniční fyzická osoba),
        // vendor=tenant check se přeskočí.
        $data = [
            'vendor'   => ['ic' => '12345678'],
            'customer' => ['ic' => null],
            'total_without_vat' => 1000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 1000]],
        ];
        $this->assertNull($this->invokeDetectWeak($data, null));
    }

    // ── maybeFlagTotalsMismatch ────────────────────────────────────────────

    public function testMismatch_clean_invoice_no_warning(): void
    {
        // Items sum sedí s AI totalem do 2 % → žádný warning.
        $this->repo->expects($this->never())->method('setExtractionWarning');
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 4113.29, 'vat_rate_id' => 1],
        ];
        $data = ['total_without_vat' => 4113.29];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_discount_with_negative_sign_no_warning(): void
    {
        // Zoner #18 po fix: sleva má v unit_price mínus, signed sum sedí s AI.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [
            ['quantity' => 12, 'unit_price_without_vat' => 5709,     'vat_rate_id' => 1], // 68508
            ['quantity' => 12, 'unit_price_without_vat' => 726,      'vat_rate_id' => 1], // 8712
            ['quantity' => 12, 'unit_price_without_vat' => -643.50,  'vat_rate_id' => 1], // -7722
        ];
        $data = ['total_without_vat' => 69498]; // signed sum: 68508+8712-7722 = 69498
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_credit_note_negative_qty_no_warning(): void
    {
        // Dobropis: extractor aplikoval `qty *= -1`. AI total je kladný (per prompt).
        // signed sum bude záporný, ale po abs() musí sednout s AI totalem.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [
            ['quantity' => -1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ['quantity' => -2, 'unit_price_without_vat' => 500,  'vat_rate_id' => 1],
        ];
        $data = ['total_without_vat' => 2000];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_discount_wrong_sign_warns_no_placeholder(): void
    {
        // Zoner PŘED sleva fix: 22 % rozdíl → warning ano, placeholder ne (<50 %).
        $this->repo->expects($this->once())
            ->method('setExtractionWarning')
            ->with(42, 1, $this->stringContains('vyšší než'));
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 12, 'unit_price_without_vat' => 5709,   'vat_rate_id' => 1],
            ['quantity' => 12, 'unit_price_without_vat' => 726,    'vat_rate_id' => 1],
            ['quantity' => 12, 'unit_price_without_vat' => 643.50, 'vat_rate_id' => 1], // chybí mínus
        ];
        $data = ['total_without_vat' => 69498.35];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_catastrophic_triggers_placeholder_with_preserved_descriptions(): void
    {
        // NC Auto >50 % mismatch → placeholder + zachované AI popisy s qty=0/price=0.
        $this->repo->expects($this->once())->method('setExtractionWarning');
        $this->repo->expects($this->once())
            ->method('replaceItems')
            ->with(42, $this->callback(function (array $items): bool {
                // První řádek = KOREKCE s AI totalem
                if (!str_starts_with($items[0]['description'], 'KOREKCE')) return false;
                if ($items[0]['unit_price_without_vat'] !== 5317.34) return false;
                if ($items[0]['quantity'] !== 1.0) return false;
                // Další řádky = AI popisy s qty=0/price=0
                if (count($items) !== 4) return false; // 1 korekce + 3 popisy
                for ($i = 1; $i <= 3; $i++) {
                    if ($items[$i]['quantity'] !== 0.0) return false;
                    if ($items[$i]['unit_price_without_vat'] !== 0.0) return false;
                }
                return $items[1]['description'] === 'Vyvážení kol'
                    && $items[2]['description'] === 'AdBlue'
                    && $items[3]['description'] === 'Závaží';
            }));

        $items = [
            ['quantity' => 14, 'unit_price_without_vat' => 1980, 'vat_rate_id' => 1, 'description' => 'Vyvážení kol'],
            ['quantity' => 15, 'unit_price_without_vat' => 31,   'vat_rate_id' => 1, 'description' => 'AdBlue'],
            ['quantity' => 8,  'unit_price_without_vat' => 52,   'vat_rate_id' => 1, 'description' => 'Závaží'],
        ];
        $data = ['total_without_vat' => 5317.34];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_skips_when_total_without_vat_missing(): void
    {
        // Bez total_without_vat nepoužíváme `total_with_vat / 1.21` fallback,
        // u multi-rate by dělal false positive. Žádný warning.
        $this->repo->expects($this->never())->method('setExtractionWarning');
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
        ];
        $data = ['total_with_vat' => 1210]; // jen s DPH; bez DPH chybí
        $this->invokeFlag(42, 1, $data, $items);
    }

    // ── applyRoundingFromAiTotal ───────────────────────────────────────────

    public function testRounding_zoner_84092_to_84093_yields_042(): void
    {
        // Zoner: items sum × 1.21 = 84092.58, AI total_with_vat (z "K úhradě") = 84093.
        // Rozdíl 0.42 → uložit jako rounding.
        $this->repo->method('find')->willReturn(['total_with_vat' => 84092.58]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, 0.42);

        $this->invokeRounding(42, 1, ['total_with_vat' => 84093], false);
    }

    public function testRounding_credit_note_applies_negative_sign(): void
    {
        // Dobropis: total_with_vat v DB záporný, AI totaly kladné (per prompt).
        // Sign aplikujeme v setRounding na záporno.
        $this->repo->method('find')->willReturn(['total_with_vat' => -84092.58]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.42);

        $this->invokeRounding(42, 1, ['total_with_vat' => 84093], true);
    }

    public function testRounding_no_diff_skips(): void
    {
        // Když je AI total = recomputed total, nic se neukládá.
        $this->repo->method('find')->willReturn(['total_with_vat' => 84092.58]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => 84092.58], false);
    }

    public function testRounding_diff_over_1_kc_skips(): void
    {
        // Rozdíl > 1 Kč není zaokrouhlení, je to chyba — ignorujeme.
        $this->repo->method('find')->willReturn(['total_with_vat' => 1000]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => 1050], false);
    }

    public function testRounding_missing_ai_total_skips(): void
    {
        $this->repo->expects($this->never())->method('find');
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => null], false);
    }

    public function testRounding_prefers_pdf_rounded_over_ai_total(): void
    {
        // Regression: user report Vodafone faktura 1025255728.
        //   Items recompute: 1×1241,34 × 1,21 = 1502,02 (= total_with_vat v DB)
        //   AI total_with_vat (její DPH math): 1502,03  ← se splete o haléř
        //   AI total_with_vat_rounded (PDF "K úhradě"): 1502,00
        //   Reálný rounding má být PDF − recompute = 1502,00 − 1502,02 = -0,02
        //   PŘED FIXEM: computeRounding bral rounded − AI total = -0,03 → "K úhradě" 1501,99
        //   PO FIXU: applyRoundingFromPdfTotal preferuje rounded, počítá vůči
        //   přesnému items totalu z DB → -0,02 → "K úhradě" 1502,00 ✓
        $this->repo->method('find')->willReturn(['total_with_vat' => 1502.02]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.02);

        $this->invokeRounding(42, 1, [
            'total_with_vat'         => 1502.03,  // AI's chybný DPH součet
            'total_with_vat_rounded' => 1502.00,  // PDF "K úhradě" — preferovat
        ], false);
    }

    // ── Helper: reflection invokers ────────────────────────────────────────

    private function invokeDetectWeak(array $data, ?string $tenantIc): ?string
    {
        $ref = new \ReflectionMethod($this->extractor, 'detectWeakExtraction');
        return $ref->invoke($this->extractor, $data, $tenantIc);
    }

    private function invokeFlag(int $invoiceId, int $supplierId, array $data, array $items): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'maybeFlagTotalsMismatch');
        $ref->invoke($this->extractor, $invoiceId, $supplierId, $data, $items);
    }

    private function invokeRounding(int $id, int $supplierId, array $data, bool $isCredit): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'applyRoundingFromPdfTotal');
        $ref->invoke($this->extractor, $id, $supplierId, $data, $isCredit);
    }
}
