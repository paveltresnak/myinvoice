<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use PHPUnit\Framework\TestCase;

/**
 * RuntimePaths (issue #53) — kořen runtime cest respektuje MYINVOICE_DATA_DIR,
 * jinak fallback na root repa. Layout musí odpovídat Config::applyDataDirOverrides.
 */
final class RuntimePathsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MYINVOICE_DATA_DIR'); // reset
    }

    public function testFallsBackToRootDirWhenDataDirUnset(): void
    {
        putenv('MYINVOICE_DATA_DIR');
        $root = Bootstrap::rootDir();

        self::assertSame($root, RuntimePaths::base());
        self::assertSame($root . '/storage', RuntimePaths::storage());
        self::assertSame($root . '/storage/invoices', RuntimePaths::storage('invoices'));
        self::assertSame($root . '/storage/supplier-logos', RuntimePaths::storage('supplier-logos'));
        self::assertSame($root . '/storage/cache/mpdf', RuntimePaths::storage('cache/mpdf'));
        self::assertSame($root . '/log', RuntimePaths::log());
        self::assertSame($root . '/log/cron', RuntimePaths::log('cron'));
    }

    public function testHonorsDataDir(): void
    {
        putenv('MYINVOICE_DATA_DIR=/srv/myinvoice-data');

        self::assertSame('/srv/myinvoice-data', RuntimePaths::base());
        self::assertSame('/srv/myinvoice-data/storage', RuntimePaths::storage());
        self::assertSame('/srv/myinvoice-data/storage/invoices', RuntimePaths::storage('invoices'));
        self::assertSame('/srv/myinvoice-data/storage/purchase-invoices', RuntimePaths::storage('purchase-invoices'));
        self::assertSame('/srv/myinvoice-data/log/cron', RuntimePaths::log('cron'));
    }

    public function testNormalizesTrailingAndLeadingSeparators(): void
    {
        putenv('MYINVOICE_DATA_DIR=/data/');
        self::assertSame('/data', RuntimePaths::base());
        self::assertSame('/data/storage/x', RuntimePaths::storage('/x'));
        self::assertSame('/data/log/y', RuntimePaths::log('/y'));
    }
}
