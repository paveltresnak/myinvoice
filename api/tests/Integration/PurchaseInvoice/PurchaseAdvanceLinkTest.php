<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Report\IncomeTaxBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Propojení přijaté zálohy (advance) s finální fakturou + dopad na náklady.
 *
 * Pokrývá:
 *   - advanceCandidates: nespárované zálohy téhož dodavatele
 *   - linkAdvance: nastaví FK + advance_paid_amount, kandidát zmizí, find() vrátí
 *     linked_advance / settled_by
 *   - UNIQUE: jednu zálohu nelze navázat na dvě finální faktury
 *   - validace: jiný dodavatel / link na ne-advance → výjimka
 *   - unlinkAdvance: vazba pryč, kandidát se vrátí
 *   - findAdvanceByReference + suggestAdvanceLink (AI návrh)
 *   - IncomeTaxBuilder: záloha NIKDY není uznatelný náklad (ani zaplacená)
 *
 * Izolováno v roce 2099 pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseAdvanceLinkTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private IncomeTaxBuilder $incomeTax;
    private GetClientAction $getClientAction;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db        = $container->get(Connection::class);
            $this->repo      = $container->get(PurchaseInvoiceRepository::class);
            $this->incomeTax = $container->get(IncomeTaxBuilder::class);
            $this->getClientAction = $container->get(GetClientAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        // FK advance_purchase_invoice_id je ON DELETE SET NULL → pořadí mazání nevadí.
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testLinkUnlinkAndCandidates(): void
    {
        $vendor = $this->vendor('Dodavatel A', 'CZ10000001');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-1', 'received', 5000.0, $this->d(10));
        $final   = $this->purchase($vendor, 'invoice', 'FAK-1', 'received', 20000.0, $this->d(20));

        // Kandidát: záloha je k dispozici k propojení
        $cands = $this->repo->advanceCandidates($final, $this->supplierId);
        self::assertCount(1, $cands, 'advanceCandidates: nespárovaná záloha téhož dodavatele');
        self::assertSame($advance, $cands[0]['id']);

        // Propojení: nastaví FK + advance_paid_amount (finální mělo 0)
        $this->repo->linkAdvance($final, $advance, $this->supplierId);
        $finalRow = $this->repo->find($final, $this->supplierId);
        self::assertSame($advance, $finalRow['advance_purchase_invoice_id']);
        self::assertNotNull($finalRow['linked_advance']);
        self::assertSame($advance, $finalRow['linked_advance']['id']);
        self::assertEqualsWithDelta(5000.0, (float) $finalRow['advance_paid_amount'], 0.01,
            'advance_paid_amount doplněn z totalu zálohy');

        // Reverzní pohled: záloha ví, kdo ji vyúčtovává
        $advRow = $this->repo->find($advance, $this->supplierId);
        self::assertNotNull($advRow['settled_by']);
        self::assertSame($final, $advRow['settled_by']['id']);

        // Po propojení už záloha není kandidátem
        self::assertCount(0, $this->repo->advanceCandidates($final, $this->supplierId));

        // Unlink → kandidát se vrátí
        $this->repo->unlinkAdvance($final, $this->supplierId);
        self::assertNull($this->repo->find($final, $this->supplierId)['advance_purchase_invoice_id']);
        self::assertCount(1, $this->repo->advanceCandidates($final, $this->supplierId));
    }

    public function testCandidatesOrderedByClosestAmount(): void
    {
        $vendor = $this->vendor('Dodavatel G', 'CZ10000007');
        $final  = $this->purchase($vendor, 'invoice', 'FAK-7', 'received', 20000.0, $this->d(20));
        // Dvě zálohy: jedna malá (5000), jedna ~ ve výši faktury (19900) → ta má být první.
        $small = $this->purchase($vendor, 'advance', 'ZAL-7S', 'received', 5000.0, $this->d(10));
        $close = $this->purchase($vendor, 'advance', 'ZAL-7C', 'received', 19900.0, $this->d(11));

        $cands = $this->repo->advanceCandidates($final, $this->supplierId);
        self::assertCount(2, $cands);
        self::assertSame($close, $cands[0]['id'], 'Nejbližší částka (19900 ≈ 20000) musí být první');
        self::assertSame($small, $cands[1]['id']);
    }

    public function testUniquePreventsDoubleLink(): void
    {
        $vendor = $this->vendor('Dodavatel B', 'CZ10000002');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-2', 'received', 3000.0, $this->d(10));
        $final1  = $this->purchase($vendor, 'invoice', 'FAK-2A', 'received', 10000.0, $this->d(20));
        $final2  = $this->purchase($vendor, 'invoice', 'FAK-2B', 'received', 12000.0, $this->d(21));

        $this->repo->linkAdvance($final1, $advance, $this->supplierId);
        $this->expectException(\Throwable::class); // UNIQUE uq_pi_advance_link
        $this->repo->linkAdvance($final2, $advance, $this->supplierId);
    }

    public function testLinkValidations(): void
    {
        $vendorA = $this->vendor('Dodavatel C', 'CZ10000003');
        $vendorB = $this->vendor('Dodavatel D', 'CZ10000004');
        $advanceA = $this->purchase($vendorA, 'advance', 'ZAL-3', 'received', 1000.0, $this->d(10));
        $finalB   = $this->purchase($vendorB, 'invoice', 'FAK-3', 'received', 5000.0, $this->d(20));

        // Jiný dodavatel → výjimka
        try {
            $this->repo->linkAdvance($finalB, $advanceA, $this->supplierId);
            self::fail('Očekávána výjimka — jiný dodavatel');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('dodavatel', $e->getMessage());
        }

        // Link na ne-advance → výjimka
        $finalA = $this->purchase($vendorA, 'invoice', 'FAK-3A', 'received', 5000.0, $this->d(21));
        $this->expectException(\Throwable::class);
        $this->repo->linkAdvance($finalA, $finalB, $this->supplierId); // finalB není advance
    }

    public function testFindByReferenceAndSuggest(): void
    {
        $vendor = $this->vendor('Dodavatel E', 'CZ10000005');
        $advance = $this->purchase($vendor, 'advance', 'ZAL 2099 007', 'received', 2000.0, $this->d(10));
        $final   = $this->purchase($vendor, 'invoice', 'FAK-5', 'received', 8000.0, $this->d(20));

        // Reference bez mezer musí najít zálohu (porovnání bez whitespace)
        $found = $this->repo->findAdvanceByReference($this->supplierId, $vendor, 'ZAL2099007');
        self::assertSame($advance, $found);

        // Uloží AI návrh (suggest & confirm) — vazba se neaplikuje
        $this->repo->suggestAdvanceLink($final, $advance, $this->supplierId);
        $finalRow = $this->repo->find($final, $this->supplierId);
        self::assertNull($finalRow['advance_purchase_invoice_id'], 'návrh neaplikuje vazbu');
        self::assertNotNull($finalRow['advance_link_suggestion']);
        self::assertSame($advance, $finalRow['advance_link_suggestion']['id']);
    }

    public function testIncomeTaxExcludesAdvanceAlways(): void
    {
        $vendor = $this->vendor('Dodavatel F', 'CZ10000006');
        // Řádná faktura → uznatelný náklad
        $this->purchase($vendor, 'invoice', 'FAK-6', 'received', 20000.0, $this->d(10));
        // Zaplacená záloha → NESMÍ být náklad (není daňový doklad), ani spárovaná
        $this->purchase($vendor, 'advance', 'ZAL-6', 'paid', 50000.0, $this->d(11));
        // Nezaplacená nespárovaná záloha → taky NESMÍ být náklad v dani z příjmů
        $this->purchase($vendor, 'advance', 'ZAL-6B', 'received', 30000.0, $this->d(12));

        $summary = $this->incomeTax->build($this->supplierId, self::YEAR, 'fo')['summary'];
        self::assertEqualsWithDelta(20000.0, $summary['costs_orientacni'], 0.01,
            'daň z příjmů: jen řádná faktura, zálohy (zaplacená i nezaplacená) vyloučené');
    }

    /**
     * Detail klienta (GetClientAction) — agregace nákladů `costs_by_year` NESMÍ
     * dvojitě započítat spárované/zaplacené zálohy (to byl bug v sumacích i grafech
     * u dodavatele). Accrual sémantika shodná s CRM (migrace 0065):
     *   - řádná faktura (received)               → náklad
     *   - spárovaná záloha                       → vyloučena (nese ji finální faktura)
     *   - zaplacená nespárovaná záloha           → vyloučena (prepayment)
     *   - nezaplacená nespárovaná záloha         → započítána (očekávaný náklad)
     */
    public function testClientDetailCostsExcludePairedAndPaidAdvance(): void
    {
        $vendor = $this->vendor('Dodavatel H', 'CZ10000008');
        $final  = $this->purchase($vendor, 'invoice', 'CD-FAK',     'received', 20000.0, $this->d(10));
        $paired = $this->purchase($vendor, 'advance', 'CD-ZAL-P',   'received',  5000.0, $this->d(9));
        $this->repo->linkAdvance($final, $paired, $this->supplierId);                       // → vyloučena
        $this->purchase($vendor, 'advance', 'CD-ZAL-PAID', 'paid',     7000.0, $this->d(8)); // → vyloučena
        $this->purchase($vendor, 'advance', 'CD-ZAL-OPEN', 'received', 3000.0, $this->d(7)); // → započítána

        $detail = $this->clientDetail($vendor);
        $czk = array_values(array_filter(
            $detail['costs_by_year'] ?? [],
            static fn ($r) => (int) $r['year'] === self::YEAR && $r['currency'] === 'CZK'
        ));
        self::assertCount(1, $czk, 'jeden CZK řádek nákladů pro rok 2099');
        self::assertEqualsWithDelta(23000.0, (float) $czk[0]['total'], 0.01,
            'náklady = řádná faktura 20000 + nezaplacená nespárovaná záloha 3000; '
            . 'spárovaná (5000) a zaplacená (7000) záloha musí být vyloučené');
        self::assertSame(2, (int) $czk[0]['count'], 'do počtu jdou jen 2 doklady (faktura + otevřená záloha)');
    }

    /**
     * Seznam přijatých faktur — měsíční mezisoučet v hlavičce (totals_per_currency)
     * NESMÍ dvojitě počítat spárované/zaplacené zálohy. Řádky se i tak všechny zobrazí.
     */
    public function testListMonthHeaderTotalsExcludeSettledAndPaidAdvance(): void
    {
        $vendor = $this->vendor('Dodavatel I', 'CZ10000009');
        $final  = $this->purchase($vendor, 'invoice', 'LH-FAK',     'received', 20000.0, $this->d(10));
        $paired = $this->purchase($vendor, 'advance', 'LH-ZAL-P',   'received',  5000.0, $this->d(9));
        $this->repo->linkAdvance($final, $paired, $this->supplierId);                       // → vyloučena
        $this->purchase($vendor, 'advance', 'LH-ZAL-PAID', 'paid',     7000.0, $this->d(8)); // → vyloučena
        $this->purchase($vendor, 'advance', 'LH-ZAL-OPEN', 'received', 3000.0, $this->d(7)); // → započítána

        $res = $this->repo->listGroupedByMonth(
            ['supplier_id' => $this->supplierId, 'vendor_id' => $vendor, 'year' => self::YEAR]
        );
        $group = null;
        foreach ($res['data'] as $g) {
            if ($g['month'] === sprintf('%04d-06', self::YEAR)) { $group = $g; break; }
        }
        self::assertNotNull($group, 'měsíční skupina 2099-06 existuje');
        self::assertSame(4, $group['count'], 'všechny 4 doklady jsou v seznamu zobrazené');

        $czk = null;
        foreach ($group['totals_per_currency'] as $tc) {
            if ($tc['currency'] === 'CZK') { $czk = $tc; break; }
        }
        self::assertNotNull($czk, 'CZK mezisoučet existuje');
        self::assertEqualsWithDelta(23000.0, (float) $czk['with_vat'], 0.01,
            'mezisoučet = faktura 20000 + otevřená záloha 3000; spárovaná (5000) a zaplacená (7000) vyloučeny');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Zavolá GetClientAction a vrátí dekódované tělo (Json::ok zapisuje data napřímo). */
    private function clientDetail(int $clientId): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/clients/' . $clientId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $resp = ($this->getClientAction)($req, new Psr7Response(), ['id' => (string) $clientId]);
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function d(int $day): string
    {
        return sprintf('%04d-06-%02d', self::YEAR, $day);
    }

    private function vendor(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /** Vloží přijatou fakturu (vat=0 → without==with, ať je daň z příjmů deterministická). */
    private function purchase(int $vendorId, string $kind, string $number, string $status, float $total, string $date): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, is_fixed_asset,
                 vat_deduction, vat_deduction_percent, tax_deductible, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, ?, 0, "full", 100, 1, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind, $date, $date, $date, $date,
            $this->currencyId, $total, $total, $status, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->piIds[] = $id;
        return $id;
    }
}
