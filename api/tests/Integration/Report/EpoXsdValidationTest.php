<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\IncomeTaxBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Integrace test: vygenerované EPO XML (DPH/KH/SH/DPFO/DPPO) MUSÍ projít XSD
 * validation MFČR schémat (storage/xsd/*.xsd).
 *
 * **Soft skip** pokud schema chybí v storage/xsd/ (production deploy nemusí mít).
 * Lokálně + CI po `bash cmd/download-xsd.sh` test fakticky validuje.
 *
 * Pozn.: vyžaduje validní supplier_id=1 s alespoň pár fakturami (smoke data).
 * Tj. test je **Integration** (DB-touching), ne Unit.
 */
final class EpoXsdValidationTest extends TestCase
{
    private XmlSchemaValidator $validator;

    /** @var array<string, callable(): array{xml: string, summary: array, warnings: array}> */
    private array $builders = [];

    protected function setUp(): void
    {
        // Soft-skip: test je Integration (vyžaduje DB s reálnými fakturami + cfg.php
        // pro DB connection). V CI runneru (GitHub Actions) cfg.php neexistuje (je
        // gitignored), takže skipujeme — Bootstrap::buildApp() by jinak fatalně padl.
        // Defenzivně skipujeme i pokud chybí XSD adresář (někdo smazal commitnutá schémata).
        // Oba checky MUSÍ proběhnout PŘED Bootstrap::buildApp(), protože jinak fatal.
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        $xsdDir = $rootDir . '/api/xsd';
        if (!is_dir($xsdDir) || count(glob($xsdDir . '/*.xsd') ?: []) === 0) {
            $this->markTestSkipped('Žádné XSD v api/xsd/ — chybí commitnutá schémata MFČR.');
        }

        $container = Bootstrap::buildApp()->getContainer();
        $this->validator = $container->get(XmlSchemaValidator::class);

        $supplierId = 1;
        $year = (int) date('Y');
        $month = (int) date('n');

        // Lazy builders — každý test si volá svůj
        $this->builders = [
            'dphdp3' => fn () => $container->get(DphPriznaniBuilder::class)
                ->build($supplierId, $year, $month, 'monthly'),
            'dphkh1' => fn () => $container->get(KontrolniHlaseniBuilder::class)
                ->build($supplierId, $year, $month),
            'dphshv' => fn () => $container->get(SouhrnneHlaseniBuilder::class)
                ->build($supplierId, $year, $month),
            'dpfdp5' => fn () => $container->get(IncomeTaxBuilder::class)
                ->build($supplierId, $year - 1, 'fo'),
            'dppdp9' => fn () => $container->get(IncomeTaxBuilder::class)
                ->build($supplierId, $year - 1, 'po'),
        ];
    }

    public function testDphdp3PassesXsdValidation(): void
    {
        $this->assertBuilderPassesXsd('dphdp3');
    }

    public function testDphkh1PassesXsdValidation(): void
    {
        $this->assertBuilderPassesXsd('dphkh1');
    }

    public function testDphshvPassesXsdValidation(): void
    {
        $this->assertBuilderPassesXsd('dphshv');
    }

    /**
     * DPFO/DPPO jsou MVP foundation — výkaz není kompletní. Test jen že XML
     * obsahuje validní strukturu (Pisemnost + DPFDP5/DPPDP9 root), ne plnou XSD
     * validaci (záměrně neúplný výkaz).
     */
    public function testDpfdp5ProducesValidXmlStructure(): void
    {
        if (!$this->validator->hasSchema('dpfdp5')) {
            $this->markTestSkipped('XSD schema dpfdp5.xsd není v storage/xsd/ — spusť `bash cmd/download-xsd.sh dpfdp5`.');
        }
        $result = ($this->builders['dpfdp5'])();
        $this->assertNotEmpty($result['xml']);
        $this->assertStringContainsString('<DPFDP5', $result['xml']);
        $this->assertStringContainsString('<Pisemnost', $result['xml']);
    }

    public function testDppdp9ProducesValidXmlStructure(): void
    {
        if (!$this->validator->hasSchema('dppdp9')) {
            $this->markTestSkipped('XSD schema dppdp9.xsd není v storage/xsd/.');
        }
        $result = ($this->builders['dppdp9'])();
        $this->assertStringContainsString('<DPPDP9', $result['xml']);
        $this->assertStringContainsString('<Pisemnost', $result['xml']);
    }

    private function assertBuilderPassesXsd(string $formCode): void
    {
        if (!$this->validator->hasSchema($formCode)) {
            $this->markTestSkipped(
                "XSD schema {$formCode}.xsd není v storage/xsd/. Stáhni přes `bash cmd/download-xsd.sh {$formCode}`."
            );
        }
        $result = ($this->builders[$formCode])();
        $this->assertNotEmpty($result['xml'], "Builder {$formCode} vrátil prázdné XML");

        $validation = $this->validator->validate($result['xml'], $formCode);
        $this->assertSame(
            'passed',
            $validation['status'],
            "XSD validation pro {$formCode} selhala s chybami:\n  - " . implode("\n  - ", $validation['errors']),
        );
        $this->assertEmpty($validation['errors'], "XSD errors v {$formCode}: " . print_r($validation['errors'], true));
    }
}
