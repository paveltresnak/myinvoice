<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfTotalExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy pro PdfTotalExtractor.
 *
 * ISDOC stage testujeme se syntetickým PDF/A-3 wrapper, který má embedded
 * minimalistický ISDOC XML — bez závislosti na reálných fakturách.
 *
 * Text regex stage testujeme přes parseMoney() reflection — sám regex
 * (závisí na smalot/pdfparser PDF parsování) testujeme integračně přes
 * jednoduchý vygenerovaný PDF v Integration suite, ne tady.
 */
final class PdfTotalExtractorTest extends TestCase
{
    private PdfTotalExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PdfTotalExtractor(new PdfIsdocExtractor());
    }

    // ── parseMoney ──────────────────────────────────────────────────────

    public function testParseMoney_czechFormatWithSpace(): void
    {
        $this->assertSame(1502.00, $this->invokeParseMoney('1 502,00'));
        $this->assertSame(1502.03, $this->invokeParseMoney('1 502,03'));
        $this->assertSame(12345.67, $this->invokeParseMoney('12 345,67'));
    }

    public function testParseMoney_czechFormatWithDotThousands(): void
    {
        // Občas se objevuje 1.502,00 (německý/český mix)
        $this->assertSame(1502.00, $this->invokeParseMoney('1.502,00'));
        $this->assertSame(123456.78, $this->invokeParseMoney('123.456,78'));
    }

    public function testParseMoney_englishFormat(): void
    {
        $this->assertSame(1502.00, $this->invokeParseMoney('1502.00'));
        $this->assertSame(1502.03, $this->invokeParseMoney('1502.03'));
    }

    public function testParseMoney_nbspThousandSeparator(): void
    {
        // PDF text extractors někdy vrátí U+00A0 (NBSP) místo regular space
        $nbsp = "\xc2\xa0";
        $this->assertSame(1502.00, $this->invokeParseMoney("1{$nbsp}502,00"));
    }

    public function testParseMoney_noDecimal(): void
    {
        // Celé číslo bez decimal separator
        $this->assertSame(1502.0, $this->invokeParseMoney('1502'));
        $this->assertSame(1502.0, $this->invokeParseMoney('1 502'));
    }

    public function testParseMoney_emptyOrJunk(): void
    {
        $this->assertSame(0.0, $this->invokeParseMoney(''));
        $this->assertSame(0.0, $this->invokeParseMoney('abc'));
    }

    public function testParseMoney_singleDecimalDigit(): void
    {
        // "1,5" — jen jedna desetinná číslice (vzácné, ale PDF občas)
        $this->assertSame(1.5, $this->invokeParseMoney('1,5'));
    }

    // ── extract() bez fallback (PDF input invalid) ──────────────────────

    public function testExtract_invalidPdfReturnsNullAndCallsAiFallback(): void
    {
        $aiCalled = false;
        $r = $this->extractor->extract('not a pdf at all', function () use (&$aiCalled) {
            $aiCalled = true;
            return 1502.00;
        });
        $this->assertSame(1502.00, $r['total']);
        $this->assertSame('ai', $r['source']);
        $this->assertTrue($aiCalled);
    }

    public function testExtract_noFallbackReturnsNull(): void
    {
        $r = $this->extractor->extract('not a pdf', null);
        $this->assertNull($r['total']);
        $this->assertNull($r['source']);
    }

    public function testExtract_aiFallbackReturnsNullKeepsTotalNull(): void
    {
        $r = $this->extractor->extract('not a pdf', fn () => null);
        $this->assertNull($r['total']);
        $this->assertNull($r['source']);
    }

    // ── tryIsdoc — syntetické PDF s embedded ISDOC XML ──────────────────

    public function testTryIsdoc_extractsPayableRoundedAmount(): void
    {
        // Mini PDF/A-3 stub s ISDOC XML přílohou (LegalMonetaryTotal/PayableRoundedAmount=1502.00)
        $isdocXml = $this->makeIsdocWithTotal('1502.00');
        $pdfBytes = $this->wrapIsdocInPdf($isdocXml);

        $r = $this->extractor->extract($pdfBytes);
        $this->assertSame(1502.00, $r['total']);
        $this->assertSame('isdoc', $r['source']);
    }

    public function testTryIsdoc_fallsBackToTaxInclusiveAmountWhenNoPayable(): void
    {
        // Bez PayableRoundedAmount, ale s TaxInclusiveAmount
        $isdocXml = '<?xml version="1.0"?>
            <Invoice xmlns="http://isdoc.cz/namespace/2013">
                <LegalMonetaryTotal>
                    <TaxInclusiveAmount>1502.02</TaxInclusiveAmount>
                </LegalMonetaryTotal>
            </Invoice>';
        $pdfBytes = $this->wrapIsdocInPdf($isdocXml);

        $r = $this->extractor->extract($pdfBytes);
        $this->assertSame(1502.02, $r['total']);
        $this->assertSame('isdoc', $r['source']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function invokeParseMoney(string $raw): float
    {
        $ref = new \ReflectionMethod($this->extractor, 'parseMoney');
        return $ref->invoke($this->extractor, $raw);
    }

    private function makeIsdocWithTotal(string $amount): string
    {
        return '<?xml version="1.0"?>
            <Invoice xmlns="http://isdoc.cz/namespace/2013">
                <ID>TEST-001</ID>
                <LegalMonetaryTotal>
                    <PayableRoundedAmount>' . $amount . '</PayableRoundedAmount>
                </LegalMonetaryTotal>
            </Invoice>';
    }

    /**
     * Minimalistický PDF/A-3 wrapper s embedded ISDOC XML.
     *
     * Skutečný PDF/A-3 by měl celý xref + catalog + fonts atd., ale
     * PdfIsdocExtractor::extract() hledá jen `/Type /EmbeddedFile` objekty
     * + ISDOC content sniff (`http://isdoc.cz/namespace/2013`). Stačí mu
     * tedy minimální stream s ISDOC namespace.
     */
    private function wrapIsdocInPdf(string $isdocXml): string
    {
        // Compress ISDOC stream FlateDecode-em (PdfIsdocExtractor to vyžaduje)
        $compressed = gzcompress($isdocXml, 9);
        if ($compressed === false) {
            $this->fail('gzcompress selhal');
        }

        // Minimalistický PDF stream:
        // %PDF-1.4 header + EmbeddedFile object s FlateDecode streamem.
        // PdfIsdocExtractor scanner najde objekt podle `/Type /EmbeddedFile` +
        // FlateDecode stream + content sniff ISDOC namespace.
        $stream = "%PDF-1.4\n"
            . "1 0 obj\n"
            . "<< /Type /EmbeddedFile /Filter /FlateDecode /Length " . strlen($compressed) . " >>\n"
            . "stream\n"
            . $compressed . "\n"
            . "endstream\n"
            . "endobj\n";
        return $stream;
    }
}
