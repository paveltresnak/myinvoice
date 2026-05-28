<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Věcná správnost zařazení dokladů do sekcí KH (DPHKH1) a řádků DPH přiznání
 * (DPHDP3) — pokrývá všechny daňové případy, které mohou nastat, a chrání proti
 * regresi oprav z issue #35 + navazujícího review:
 *
 *   A.1 RC dodavatel · A.2 pořízení z JČS · A.4/A.5 tuzemská vystavená · B.1 RC
 *   příjemce · B.2/B.3 tuzemská přijatá · dobropis se záporným základem · doklad
 *   bez DUZP · doklad bez DIČ nad limit · dodání/vývoz do EU (oddíl C ř.20-26) ·
 *   samovyměření DPH (ř.3/10 + mirror ř.43) · pořízení majetku (ř.47).
 *
 * Vytvoří vlastní klienty + faktury + přijaté faktury v izolovaném období
 * (rok 2099, měsíc 6) pod existujícím supplierem, ověří XML, vše uklidí v tearDown.
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class KhDphTaxScenariosTest extends TestCase
{
    private const YEAR = 2099;
    private const MONTH = 6;

    private Connection $db;
    private KontrolniHlaseniBuilder $kh;
    private DphPriznaniBuilder $dph;
    private DphBookBuilder $book;
    private SouhrnneHlaseniBuilder $shv;
    private PurchaseInvoiceRepository $piRepo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;
    private int $skId = 0;

    /** @var array{customers:int[], vendors:int[]} */
    private array $clientIds = ['customers' => [], 'vendors' => []];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->kh   = $container->get(KontrolniHlaseniBuilder::class);
            $this->dph  = $container->get(DphPriznaniBuilder::class);
            $this->book = $container->get(DphBookBuilder::class);
            $this->shv  = $container->get(SouhrnneHlaseniBuilder::class);
            $this->piRepo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = $this->countryId('CZ');
        $this->deId = $this->countryId('DE');
        $this->skId = $this->countryId('SK');

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_merge($this->clientIds['customers'], $this->clientIds['vendors']) as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close(); // uvolni MySQL connection (kumulace přes běh → max_connections)
    }

    public function testAllTaxScenariosClassifyCorrectly(): void
    {
        // ── Protistrany ──────────────────────────────────────────────────────
        $custDic   = $this->client('Odběratel s DIČ',  $this->czId, 'CZ11111118', customer: true);
        $custNoDic = $this->client('Odběratel bez DIČ', $this->czId, null,        customer: true);
        $euCust    = $this->client('EU odběratel',      $this->skId, 'SK1234567',  customer: true);
        $vendDic   = $this->client('Dodavatel s DIČ',   $this->czId, 'CZ22222220', vendor: true);
        $vendNoDic = $this->client('Dodavatel bez DIČ', $this->czId, null,         vendor: true);
        $euVend    = $this->client('EU dodavatel',      $this->deId, 'DE123456789', vendor: true);

        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);

        // ── VYSTAVENÉ (sales) ────────────────────────────────────────────────
        // S1 A.4: tuzemská 21 % nad limit, odběratel s DIČ
        $this->sale('2099060001', $custDic, '1', false, $d(10), $d(10), [[20000, 4200, 21]]);
        // S2 A.5: tuzemská 21 % do limitu
        $this->sale('2099060002', $custDic, '1', false, $d(11), $d(11), [[5000, 1050, 21]]);
        // S3 A.5: tuzemská 21 % nad limit, ale BEZ DIČ → sumace (ne zahodit) — issue #35 #4
        $this->sale('2099060003', $custNoDic, '1', false, $d(12), $d(12), [[30000, 6300, 21]]);
        // S4 oddíl C: vývoz (kód 26 → ř.22 pln_vyvoz) — issue #35 #2
        $this->sale('2099060004', $euCust, '26', false, $d(13), $d(13), [[50000, 0, 0]]);
        // S5 A.1: reverse charge dodavatel (samovyměří odběratel) — RC sleva sazby na 0
        $this->sale('2099060005', $custDic, null, true, $d(14), $d(14), [[15000, 0, 0]]);

        // ── PŘIJATÉ (purchases) ──────────────────────────────────────────────
        // P1 B.2: tuzemská 21 % nad limit, dodavatel s DIČ
        $this->purchase('P-2099-001', $vendDic, '40', false, 'invoice', $d(12), $d(12), [[10000, 2100, 21]]);
        // P2 B.3: tuzemská 21 % do limitu
        $this->purchase('P-2099-002', $vendDic, '40', false, 'invoice', $d(12), $d(12), [[2000, 420, 21]]);
        // P3 B.3: nad limit ale BEZ DIČ → sumace B.3 — issue #35 #4
        $this->purchase('P-2099-003', $vendNoDic, '40', false, 'invoice', $d(13), $d(13), [[15000, 3150, 21]]);
        // P4 A.2: pořízení zboží z JČS (kód 23, RC) — jen A.2, NE B.2 — issue #35 #1
        $this->purchase('P-2099-004', $euVend, '23', true, 'invoice', $d(14), $d(14), [[8000, 0, 21]]);
        // P5 B.1: tuzemský RC příjemce (kód 5) — flag reverse_charge=0 testuje migraci is_reverse_charge — review #3
        $this->purchase('P-2099-005', $vendDic, '5', false, 'invoice', $d(15), $d(15), [[9000, 0, 21]]);
        // P6 B.2: bez DUZP (tax_date NULL), issue_date v období — COALESCE fix
        $this->purchase('P-2099-006', $vendDic, '40', false, 'invoice', $d(15), null, [[11000, 2310, 21]]);
        // P7 B.2: dobropis se záporným základem nad limit — issue #35 #2
        $this->purchase('P-2099-007', $vendDic, '40', false, 'credit_note', $d(20), $d(20), [[-25000, -5250, 21]]);
        // P8 B.2 + ř.47: pořízení dlouhodobého majetku
        $this->purchase('P-2099-008', $vendDic, '40', false, 'invoice', $d(22), $d(22), [[40000, 8400, 21]], isFixedAsset: true);

        // ══ KONTROLNÍ HLÁŠENÍ ════════════════════════════════════════════════
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $root = $kh->DPHKH1;

        // A.4 — jen S1 (nad limit + DIČ)
        $this->assertCount(1, $root->VetaA4, 'A.4: očekáván právě 1 doklad (S1)');
        $this->assertSame('20000.00', (string) $root->VetaA4[0]['zakl_dane1']);
        $this->assertSame('11111118', (string) $root->VetaA4[0]['dic_odb']);

        // A.5 — sumace S2 + S3 (S3 je nad limit, ale bez DIČ → sem, ne zahodit)
        $this->assertSame('35000.00', (string) $root->VetaA5['zakl_dane1'], 'A.5: 5000 (S2) + 30000 (S3 bez DIČ)');
        $this->assertSame('7350.00',  (string) $root->VetaA5['dan1']);

        // A.1 — RC dodavatel (S5)
        $this->assertCount(1, $root->VetaA1, 'A.1: RC vystavené (S5)');
        $this->assertSame('15000.00', (string) $root->VetaA1[0]['zakl_dane1']);

        // A.2 — pořízení z JČS (P4), samovyměřená daň 21 %
        $this->assertCount(1, $root->VetaA2, 'A.2: pořízení zboží z JČS (P4)');
        $this->assertSame('8000.00', (string) $root->VetaA2[0]['zakl_dane1']);
        $this->assertSame('1680.00', (string) $root->VetaA2[0]['dan1'], 'A.2: samovyměřená daň 8000×21 %');

        // B.1 — tuzemský RC příjemce (P5) — díky migraci is_reverse_charge=1 i bez flagu
        $this->assertCount(1, $root->VetaB1, 'B.1: tuzemský RC příjemce (P5)');
        $this->assertSame('9000.00', (string) $root->VetaB1[0]['zakl_dane1']);

        // B.2 — P1, P6 (bez DUZP), P7 (dobropis −), P8 (majetek). NE P4 (A.2) ani P5 (B.1)!
        $b2bases = [];
        foreach ($root->VetaB2 as $v) $b2bases[] = (string) $v['zakl_dane1'];
        sort($b2bases);
        $this->assertSame(['-25000.00', '10000.00', '11000.00', '40000.00'], $b2bases,
            'B.2: P1+P6+P7+P8; A.2 (P4) a B.1 (P5) se NESMÍ duplikovat do B.2');

        // B.3 — sumace P2 + P3 (P3 nad limit bez DIČ)
        $this->assertSame('17000.00', (string) $root->VetaB3['zakl_dane1'], 'B.3: 2000 (P2) + 15000 (P3 bez DIČ)');
        $this->assertSame('3570.00',  (string) $root->VetaB3['dan1']);

        // ══ DPH PŘIZNÁNÍ ═════════════════════════════════════════════════════
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $dp = $dphXml->DPHDP3;
        $v1 = $dp->Veta1;
        $v2 = $dp->Veta2;
        $v4 = $dp->Veta4;

        // ř.1 výstup 21 % = S1+S2+S3 (RC sale a vývoz sem nepatří)
        $this->assertSame('55000', (string) $v1['obrat23'], 'ř.1 základ = 20000+5000+30000');
        $this->assertSame('11550', (string) $v1['dan23'],   'ř.1 daň = 4200+1050+6300');

        // Oddíl C / Veta2 ř.22 vývoz (S4) — dříve se negeneroval vůbec (review #2)
        $this->assertNotNull($v2, 'Veta2 (oddíl C) musí existovat');
        $this->assertSame('50000', (string) $v2['pln_vyvoz'], 'ř.22 vývoz = 50000 (S4)');

        // ř.3 pořízení zboží z JČS (P4) + samovyměřená daň
        $this->assertSame('8000', (string) $v1['p_zb23']);
        $this->assertSame('1680', (string) $v1['dan_pzb23']);

        // ř.10 tuzemský RC příjemce (P5) + samovyměřená daň (migrace is_reverse_charge)
        $this->assertSame('9000', (string) $v1['rez_pren23']);
        $this->assertSame('1890', (string) $v1['dan_rpren23']);

        // ř.40 odpočet tuzemsko 21 % = P1+P2+P3+P6+P7(−)+P8
        $this->assertSame('53000', (string) $v4['pln23'], 'ř.40 základ = 10000+2000+15000+11000−25000+40000');
        $this->assertSame('11130', (string) $v4['odp_tuz23_nar']);

        // ř.43 RC mirror odpočet = A.2 (P4) + B.1 (P5)
        $this->assertSame('17000', (string) $v4['odp_rezim'], 'ř.43 = 8000 (P4) + 9000 (P5)');
        $this->assertSame('3570',  (string) $v4['odp_rez_nar']);

        // ř.47 hodnota pořízeného majetku (P8)
        $this->assertSame('40000', (string) $v4['nar_maj'], 'ř.47 = 40000 (P8 majetek)');

        // ══ KNIHA DPH (interní žurnál) ═══════════════════════════════════════
        // Pin chování PŘED refaktorem na sdílenou VatLedgerService — Kniha DPH
        // musí nad stejnými daty dávat konzistentní základy/daně s DPHDP3.
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) {
            $sec[$s['key']] = $s;
        }

        // 36.001 — vystavená tuzemsko 21 % (S1+S2+S3) = ř.1 DPHDP3
        $this->assertArrayHasKey('36.001', $sec);
        $this->assertEqualsWithDelta(55000, $sec['36.001']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(11550, $sec['36.001']['subtotal_vat'], 0.01);
        // 36.022 — vývoz (S4, kód 26 → ř.22)
        $this->assertArrayHasKey('36.022', $sec, 'Kniha DPH: sekce vývozu ř.22');
        $this->assertEqualsWithDelta(50000, $sec['36.022']['subtotal_base'], 0.01);
        // 15.040 — přijatá tuzemsko 21 % (P1+P2+P3+P6+P7−+P8) = ř.40
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(53000, $sec['15.040']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(11130, $sec['15.040']['subtotal_vat'], 0.01);
        // 15.003 — pořízení z JČS (P4), samovyměřená daň
        $this->assertArrayHasKey('15.003', $sec);
        $this->assertEqualsWithDelta(8000, $sec['15.003']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(1680, $sec['15.003']['subtotal_vat'], 0.01);
        // 15.010 — tuzemský RC (P5) — samovyměření i BEZ per-faktura flagu
        // (díky is_reverse_charge na kódu 5 / migrace 0048). Toto pinuje fix konzistence.
        $this->assertArrayHasKey('15.010', $sec, 'P5 RC bez flagu musí mít sekci ř.10');
        $this->assertEqualsWithDelta(9000, $sec['15.010']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(1890, $sec['15.010']['subtotal_vat'], 0.01,
            'Kniha DPH musí samovyměřit RC i přes is_reverse_charge, ne jen flag');
        // 43.043 — mirror odpočet u samovyměřené daně (P4 + P5)
        $this->assertArrayHasKey('43.043', $sec);
        $this->assertEqualsWithDelta(17000, $sec['43.043']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(3570, $sec['43.043']['subtotal_vat'], 0.01);
        // 47.047 — hodnota pořízeného majetku (P8)
        $this->assertArrayHasKey('47.047', $sec);
        $this->assertEqualsWithDelta(40000, $sec['47.047']['subtotal_base'], 0.01);

        // Souhrny MUSÍ být oddělené pro uskutečněná (výstup) a přijatá (odpočet) —
        // sčítat je dohromady nedává smysl. Secondary sekce (43/47) se nezapočítávají.
        $this->assertEqualsWithDelta(11550, $book['totals']['issued']['vat'], 0.01,
            'totals.issued = jen daň na výstupu (36.001)');
        $this->assertEqualsWithDelta(14700, $book['totals']['received']['vat'], 0.01,
            'totals.received = odpočet na vstupu (15.040+15.003+15.010), bez mirror 43/47');
        // Bilance = výstup − odpočet (záporná = nadměrný odpočet).
        $this->assertEqualsWithDelta(-3150, $book['totals']['vat_balance'], 0.01);
    }

    /**
     * Daňově korektní zařazení do období když se DUZP a datum vystavení rozcházejí
     * přes hranici měsíce (DUZP 06/2099, vystaveno 07/2099):
     *
     *   - VYSTAVENÁ → patří do června (daň na výstupu vzniká k DUZP),
     *   - PŘIJATÁ   → patří do července (odpočet nelze uplatnit dřív, než plátce drží
     *                 daňový doklad — § 73 ZDPH; zpětný DUZP nepřesune doklad do června).
     */
    public function testStraddlingMonthAssignsIssuedByDuzpAndReceivedByLater(): void
    {
        $custDic = $this->client('Odběratel přelom', $this->czId, 'CZ66666664', customer: true);
        $vendDic = $this->client('Dodavatel přelom', $this->czId, 'CZ77777771', vendor: true);

        $juneTax = sprintf('%04d-06-25', self::YEAR);  // DUZP červen
        $julyIss = sprintf('%04d-07-05', self::YEAR);  // vystaveno červenec

        // VF: DUZP 25.6., vystavená 5.7. → základ 7000
        $this->sale('2099069001', $custDic, '1', false, $julyIss, $juneTax, [[7000, 1470, 21]]);
        // PF: DUZP 25.6., vystavená 5.7. → základ 5000
        $this->purchase('P-2099-901', $vendDic, '40', false, 'invoice', $julyIss, $juneTax, [[5000, 1050, 21]]);

        $sectionsFor = function (int $month): array {
            $book = $this->book->build($this->supplierId, self::YEAR, $month);
            $sec = [];
            foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
            return $sec;
        };

        // ── ČERVEN: jen vystavená (DUZP), přijatá tu NESMÍ být ──
        $june = $sectionsFor(6);
        $this->assertArrayHasKey('36.001', $june, 'VF s DUZP 06 patří do června');
        $this->assertEqualsWithDelta(7000, $june['36.001']['subtotal_base'], 0.01);
        $this->assertArrayNotHasKey('15.040', $june,
            'PF vystavená až 07 NESMÍ být v červnu (odpočet nelze uplatnit před doručením dokladu)');

        // ── ČERVENEC: jen přijatá (pozdější datum), vystavená je už v červnu ──
        $july = $sectionsFor(7);
        $this->assertArrayHasKey('15.040', $july, 'PF vystavená 07 patří do července');
        $this->assertEqualsWithDelta(5000, $july['15.040']['subtotal_base'], 0.01);
        $this->assertArrayNotHasKey('36.001', $july, 'VF se řadí dle DUZP (červen), ne dle vystavení');

        // ── Totéž musí platit i pro oficiální DPHDP3 (sdílí VatLedgerService) ──
        $dphJune = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, 6, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('7000', (string) $dphJune->Veta1['obrat23'], 'DPHDP3/06 ř.1: VF dle DUZP');
        $this->assertNotSame('5000', (string) $dphJune->Veta4['pln23'], 'DPHDP3/06 ř.40: PF tu být NESMÍ');

        $dphJuly = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, 7, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('5000', (string) $dphJuly->Veta4['pln23'], 'DPHDP3/07 ř.40: PF dle pozdějšího data');
        $this->assertNotSame('7000', (string) $dphJuly->Veta1['obrat23'], 'DPHDP3/07 ř.1: VF tu být NESMÍ');
    }

    /**
     * Regrese: faktura s vat_deduction='none' (bez nároku na odpočet — reprezentace
     * apod.) NESMÍ vstoupit do Knihy DPH, DPHDP3 (ř.40) ani KH. Plný nárok ano.
     */
    public function testVatDeductionNoneExcludedFromVatReports(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel reprez.', $this->czId, 'CZ33333339', vendor: true);

        // Plný nárok → vstupuje do DPH (10000 základ, 2100 DPH)
        $this->purchase('P-2099-100', $vend, '40', false, 'invoice', $d(10), $d(10), [[10000, 2100, 21]]);
        // Bez nároku (reprezentace) → NESMÍ se objevit nikde v DPH evidenci
        $this->purchase('P-2099-101', $vend, '40', false, 'invoice', $d(11), $d(11), [[7000, 1470, 21]], vatDeduction: 'none');

        // Kniha DPH — ř.40 jen 10000, none vyloučeno
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(10000, $sec['15.040']['subtotal_base'], 0.01,
            'Faktura bez nároku (none) nesmí vstoupit do Knihy DPH');
        $this->assertEqualsWithDelta(2100, $book['totals']['received']['vat'], 0.01,
            'Odpočet jen z plného nároku (2100), ne z none (1470)');

        // DPHDP3 ř.40 odpočet jen 10000
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('10000', (string) $dphXml->DPHDP3->Veta4['pln23'],
            'ř.40 = jen plný nárok (none vyloučeno)');
    }

    /**
     * Regrese: přijatá zálohová / proforma (document_kind='advance') NENÍ daňový
     * doklad → NESMÍ vstoupit do Knihy DPH, DPHDP3 (ř.40) ani KH (B.2/B.3).
     * Symetricky k výstupní straně, kde se vylučuje invoice_type='proforma'.
     */
    public function testReceivedAdvanceProformaExcludedFromVatReports(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel záloha', $this->czId, 'CZ99999990', vendor: true);

        // Řádná přijatá faktura → vstupuje do DPH (10000 / 2100)
        $this->purchase('P-2099-400', $vend, '40', false, 'invoice', $d(10), $d(10), [[10000, 2100, 21]]);
        // Zálohová / proforma (advance) → NESMÍ se objevit nikde v DPH evidenci
        $this->purchase('P-2099-401', $vend, '40', false, 'advance', $d(11), $d(11), [[50000, 10500, 21]]);

        // Kniha DPH — ř.40 jen řádná faktura (10000), advance vyloučena
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(10000, $sec['15.040']['subtotal_base'], 0.01,
            'Přijatá proforma (advance) nesmí vstoupit do Knihy DPH');
        $this->assertEqualsWithDelta(2100, $book['totals']['received']['vat'], 0.01,
            'Odpočet jen z řádné faktury (2100), ne z advance (10500)');

        // DPHDP3 ř.40 odpočet jen 10000
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('10000', (string) $dphXml->DPHDP3->Veta4['pln23'],
            'ř.40 = jen řádná faktura (advance vyloučena)');

        // KH B.2 — jen řádná faktura, advance nesmí přidat druhý záznam
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $b2bases = [];
        foreach ($kh->DPHKH1->VetaB2 as $v) $b2bases[] = (string) $v['zakl_dane1'];
        $this->assertSame(['10000.00'], $b2bases, 'KH B.2: jen řádná faktura, advance vyloučena');
    }

    /**
     * Regrese (daňový audit 2026-05-28): dovoz služby z EU (kód 24) se musí
     * SAMOVYMĚŘIT i BEZ ručního zaškrtnutí RC flagu na dokladu — díky
     * is_reverse_charge=1 na kódu (migrace 0063). Výstup ř.12 i zrcadlový
     * odpočet ř.43 musí mít nenulovou daň.
     */
    public function testImportedServiceSelfAssessesWithoutInvoiceFlag(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel služba EU', $this->deId, 'DE111111111', vendor: true);

        // Kód 24 (dovoz služby), reverse_charge FLAG = false → spoléháme jen na kód.
        // Vendor fakturuje bez DPH (vat=0), sazba 21 %.
        $this->purchase('P-2099-500', $vend, '24', false, 'invoice', $d(10), $d(10), [[10000, 0, 21]]);

        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $dp = $dphXml->DPHDP3;
        // ř.12 výstup (dovoz služby) — samovyměřená daň 2100 i bez flagu
        $this->assertSame('10000', (string) $dp->Veta1['p_sl23_z'], 'ř.12 základ dovoz služby');
        $this->assertSame('2100',  (string) $dp->Veta1['dan_psl23_z'], 'ř.12 daň samovyměřena z kódu (ne z flagu)');
        // ř.43 zrcadlový odpočet
        $this->assertSame('10000', (string) $dp->Veta4['odp_rezim'], 'ř.43 mirror základ');
        $this->assertSame('2100',  (string) $dp->Veta4['odp_rez_nar'], 'ř.43 mirror odpočet');

        // Kniha DPH — sekce 15.012 (dovoz služby) a 43.043 (mirror)
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('15.012', $sec, 'Kniha: sekce ř.12 dovoz služby');
        $this->assertEqualsWithDelta(2100, $sec['15.012']['subtotal_vat'], 0.01, 'Kniha ř.12 samovyměřená daň');
    }

    /**
     * Regrese (daňový audit 2026-05-28): přijaté plnění bez nároku na odpočet
     * (kód 42, dphdp3_line=NULL) NESMÍ spadnout do KH B.2/B.3, přestože má
     * nenulový základ v sazbě 21 %. DPHDP3 ho rovněž vynechává.
     */
    public function testNonDeductiblePurchaseExcludedFromKh(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel bez nároku', $this->czId, 'CZ12121219', vendor: true);

        // Řádná odpočtová faktura (kód 40) nad limit → B.2
        $this->purchase('P-2099-600', $vend, '40', false, 'invoice', $d(10), $d(10), [[20000, 4200, 21]]);
        // Bez nároku (kód 42, 21 % bez nároku) nad limit → NESMÍ do B.2/B.3
        $this->purchase('P-2099-601', $vend, '42', false, 'invoice', $d(11), $d(11), [[30000, 6300, 21]]);

        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $b2bases = [];
        foreach ($kh->DPHKH1->VetaB2 as $v) $b2bases[] = (string) $v['zakl_dane1'];
        $this->assertSame(['20000.00'], $b2bases, 'KH B.2: jen kód 40, kód 42 (bez nároku) vyloučen');
        // B.3 (do limitu) musí zůstat prázdné — kód 42 tam taky nesmí
        $this->assertCount(0, $kh->DPHKH1->VetaB3, 'KH B.3: kód 42 nesmí padnout ani do sumace');

        // DPHDP3 ř.40 jen odpočtová faktura
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('20000', (string) $dphXml->DPHDP3->Veta4['pln23'], 'ř.40 jen kód 40');
    }

    /**
     * Regrese (daňový audit 2026-05-28): osvobozené tuzemské vystavené plnění
     * (kód 3, sazba 0 %) NESMÍ spadnout na ř.3 DPHDP3 (= pořízení zboží z JČS,
     * vstup) — to byla seedová chyba "kód=řádek". Po migraci 0063 (dphdp3_line=NULL)
     * se do DPHDP3 ani KH nevykazuje.
     */
    public function testExemptDomesticSaleDoesNotLandOnLine3(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $cust = $this->client('Odběratel osvobozeno', $this->czId, 'CZ15151512', customer: true);

        // Osvobozené tuzemské plnění (kód 3), sazba 0 %, základ 80000.
        $this->sale('2099068001', $cust, '3', false, $d(10), $d(10), [[80000, 0, 0]]);

        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $dp = $dphXml->DPHDP3;
        // ř.3 (pořízení zboží z JČS) NESMÍ obsahovat základ osvobozeného prodeje.
        $this->assertNotSame('80000', (string) $dp->Veta1['p_zb23'], 'osvobozený prodej nesmí korumpovat ř.3');
        $this->assertSame('', (string) $dp->Veta1['p_zb23'], 'ř.3 musí zůstat prázdný (žádné pořízení z EU)');

        // KH — osvobozené plnění (0 %) nepatří do A.4/A.5.
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(0, $kh->DPHKH1->VetaA4, 'osvobozený prodej nepatří do A.4');
        $this->assertCount(0, $kh->DPHKH1->VetaA5, 'osvobozený prodej nepatří do A.5 (sumace)');
    }

    /**
     * Regrese (daňový audit 2026-05-28): DPHDP3 generuje Veta6 (rekapitulace) —
     * ř.62 daň na výstupu, ř.63 odpočet, ř.64 vlastní daň / ř.66 nadměrný odpočet.
     */
    public function testDphPriznaniEmitsVeta6Recap(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $cust = $this->client('Odběratel recap', $this->czId, 'CZ13131316', customer: true);
        $vend = $this->client('Dodavatel recap', $this->czId, 'CZ14141413', vendor: true);

        // Výstup: 50000 × 21 % = 10500 daň. Odpočet: 20000 × 21 % = 4200.
        // Vlastní daň = 10500 − 4200 = 6300 (kladná → dano_da).
        $this->sale('2099067001', $cust, '1', false, $d(10), $d(10), [[50000, 10500, 21]]);
        $this->purchase('P-2099-700', $vend, '40', false, 'invoice', $d(11), $d(11), [[20000, 4200, 21]]);

        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        $this->assertNotNull($dp->Veta6, 'Veta6 (rekapitulace) musí existovat');
        $this->assertSame('10500', (string) $dp->Veta6['dan_zocelk'], 'ř.62 daň na výstupu celkem');
        $this->assertSame('4200',  (string) $dp->Veta6['odp_zocelk'], 'ř.63 odpočet celkem');
        $this->assertSame('6300',  (string) $dp->Veta6['dano_da'], 'ř.64 vlastní daňová povinnost');
        $this->assertSame('',      (string) $dp->Veta6['dano_no'], 'ř.66 nadměrný odpočet nesmí být vyplněn');
    }

    /**
     * §75 poměrný odpočet: vat_deduction='proportional' s percentem zkrátí
     * odpočet (základ i daň) v Knize DPH i DPHDP3 (ř.40) o dané procento.
     */
    public function testProportionalDeductionScalesByPercent(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel auto', $this->czId, 'CZ44444448', vendor: true);

        // Auto 70 % business: základ 10000, DPH 2100 → odpočet jen 7000 / 1470
        $this->purchase('P-2099-200', $vend, '40', false, 'invoice', $d(10), $d(10), [[10000, 2100, 21]],
            vatDeduction: 'proportional', vatDeductionPercent: 70.0);

        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(7000, $sec['15.040']['subtotal_base'], 0.01, 'ř.40 základ × 70 %');
        $this->assertEqualsWithDelta(1470, $sec['15.040']['subtotal_vat'], 0.01, 'ř.40 daň × 70 %');

        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('7000', (string) $dphXml->DPHDP3->Veta4['pln23'], 'DPHDP3 ř.40 = 7000 (70 %)');
    }

    /**
     * Změna daňového uplatnění u už očíslované faktury přepíše PREFIX interního
     * čísla (varsymbol) na nový typ a zachová číselnou řadu. Ruční číslo neměníme.
     */
    public function testReprefixVarsymbolOnTaxTreatmentChange(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel přečíslo', $this->czId, 'CZ55555556', vendor: true);

        // Faktura bez nároku (none), ale s číslem PF (jako by byla původně plný nárok).
        $this->purchase('REPFX-1', $vend, '40', false, 'invoice', $d(10), $d(10), [[1000, 210, 21]], vatDeduction: 'none');
        $id = (int) end($this->purchaseIds);
        $pdo = $this->db->pdo();
        // none + neuznatelný (tax_deductible=0) → očekávaný prefix NN.
        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ?, tax_deductible = 0 WHERE id = ?')->execute(['PF2099001', $id]);

        $this->piRepo->reprefixVarsymbol($id, $this->supplierId);
        $vs = (string) $pdo->query("SELECT varsymbol FROM purchase_invoices WHERE id = $id")->fetchColumn();
        self::assertSame('NN2099001', $vs, 'none + neuznatelný → prefix NN, řada zachována');

        // Ruční (cizí) číslo se NEpřepisuje.
        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ?')->execute(['FAK-2099/7', $id]);
        $this->piRepo->reprefixVarsymbol($id, $this->supplierId);
        $vs2 = (string) $pdo->query("SELECT varsymbol FROM purchase_invoices WHERE id = $id")->fetchColumn();
        self::assertSame('FAK-2099/7', $vs2, 'ruční číslo se nepřepisuje');
    }

    /**
     * §75 poměrný odpočet: doklad nad limit s DIČ se v KH (B.2) označí pomer='A'
     * (částky jsou už zkrácené). Plný nárok → pomer='N'.
     */
    public function testProportionalDeductionMarksKhPomer(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel pomer', $this->czId, 'CZ88888887', vendor: true);

        // Plný nárok, gross 24200 (nad limit) → B.2 pomer N, základ 20000
        $this->purchase('P-2099-300', $vend, '40', false, 'invoice', $d(10), $d(10), [[20000, 4200, 21]]);
        // Poměrný 50 %, gross 12100 (nad limit) → B.2 pomer A, zkrácený základ 5000
        $this->purchase('P-2099-301', $vend, '40', false, 'invoice', $d(11), $d(11), [[10000, 2100, 21]],
            vatDeduction: 'proportional', vatDeductionPercent: 50.0);

        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $pomerByBase = [];
        foreach ($kh->DPHKH1->VetaB2 as $v) {
            $pomerByBase[(string) $v['zakl_dane1']] = (string) $v['pomer'];
        }
        $this->assertSame('N', $pomerByBase['20000.00'] ?? null, 'Plný nárok → pomer=N');
        $this->assertSame('A', $pomerByBase['5000.00'] ?? null, 'Poměrný §75 → pomer=A (zkrácený základ 5000)');
    }

    /**
     * Souhrnné hlášení: kód plnění (k_pln_eu) dle DPHSHV XSD —
     *   dodání zboží do JČS → 0, služba do JČS (§9/1) → 3,
     *   třístranný obchod prostřední osobou (§17) → 2.
     * Plus DPHDP3: ř.20 (dod_zb), ř.21 (pln_sluzby), ř.31 (tri_dozb / Veta3).
     */
    public function testEuSupplyShvCodesAndTriangular(): void
    {
        // SHV vyžaduje EU zemi — pokud seed countries nemá SK jako EU, přeskoč.
        $skEu = (int) ($this->db->pdo()->query("SELECT COALESCE(is_eu,0) FROM countries WHERE iso2='SK' LIMIT 1")->fetchColumn() ?: 0);
        if ($skEu !== 1) {
            $this->markTestSkipped('SK není v countries označeno jako EU — SHV test přeskočen.');
        }

        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $euCust = $this->client('EU odběratel SHV', $this->skId, 'SK7654321', customer: true);

        // Dodání zboží do JČS (kód 20 → SHV 0, DPHDP3 ř.20)
        $this->sale('2099063001', $euCust, '20', false, $d(10), $d(10), [[10000, 0, 0]]);
        // Poskytnutí služby do JČS (kód 22 → SHV 3, DPHDP3 ř.21)
        $this->sale('2099063002', $euCust, '22', false, $d(11), $d(11), [[5000, 0, 0]]);
        // Třístranný obchod — dodání prostřední osobou (kód 31 → SHV 2, DPHDP3 ř.31)
        $this->sale('2099063003', $euCust, '31', false, $d(12), $d(12), [[7000, 0, 0]]);

        // ── SHV: kódy plnění ──
        $shv = $this->shv->build($this->supplierId, self::YEAR, self::MONTH);
        $amountByType = [];
        foreach ($shv['summary']['rows'] as $r) {
            $amountByType[(string) $r['sh_type']] = (float) $r['amount'];
        }
        $this->assertEqualsWithDelta(10000, $amountByType['0'] ?? -1, 0.01, 'SHV kód 0 = dodání zboží');
        $this->assertEqualsWithDelta(5000,  $amountByType['3'] ?? -1, 0.01, 'SHV kód 3 = služba §9/1 (dříve chybně 2)');
        $this->assertEqualsWithDelta(7000,  $amountByType['2'] ?? -1, 0.01, 'SHV kód 2 = třístranný obchod (prostřední osoba)');

        // ── DPHDP3: oddíl C ──
        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('10000', (string) $dp->Veta2['dod_zb'],     'ř.20 dodání zboží do JČS');
        $this->assertSame('5000',  (string) $dp->Veta2['pln_sluzby'], 'ř.21 služby do JČS');
        $this->assertNotNull($dp->Veta3, 'Veta3 (oddíl C) musí existovat pro třístranný obchod');
        $this->assertSame('7000',  (string) $dp->Veta3['tri_dozb'],   'ř.31 dodání zboží prostřední osobou');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function client(string $name, int $countryId, ?string $dic, bool $customer = false, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[$vendor ? 'vendors' : 'customers'][] = $id;
        return $id;
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function sale(string $varsymbol, int $clientId, ?string $code, bool $rc, string $issue, string $tax, array $items): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, "issued", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $issue, $tax, $issue,
            $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->insertItems('invoice_items', 'invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function purchase(string $number, int $vendorId, ?string $code, bool $rc, string $kind, string $issue, ?string $tax, array $items, bool $isFixedAsset = false, string $vatDeduction = 'full', float $vatDeductionPercent = 100.0): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, "received", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind, $issue, $tax, $issue, $issue,
            $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $isFixedAsset ? 1 : 0, $vatDeduction, $vatDeductionPercent, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->insertItems('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     * @return array{0:float,1:float,2:float} [base, vat, with]
     */
    private function sumItems(array $items): array
    {
        $base = 0.0; $vat = 0.0;
        foreach ($items as $it) { $base += $it[0]; $vat += $it[1]; }
        return [$base, $vat, $base + $vat];
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     */
    private function insertItems(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => $it) {
            [$base, $vat, $snapshot] = $it;
            $stmt->execute([$id, $base, $this->vatRateId, $snapshot, $base, $vat, $base + $vat, $i]);
        }
    }
}
