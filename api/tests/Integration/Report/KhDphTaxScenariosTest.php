<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
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
    private function purchase(string $number, int $vendorId, ?string $code, bool $rc, string $kind, string $issue, ?string $tax, array $items, bool $isFixedAsset = false): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, "received", ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind, $issue, $tax, $issue, $issue,
            $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $isFixedAsset ? 1 : 0, $this->userId,
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
