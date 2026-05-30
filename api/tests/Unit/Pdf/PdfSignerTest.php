<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Pdf;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Pdf\PdfSigner;
use MyInvoice\Service\Pdf\SigningConfig;
use PHPUnit\Framework\TestCase;

final class PdfSignerTest extends TestCase
{
    private static string $p12Path = '';
    private static string $certPemPath = '';
    private static ?string $skipReason = null;
    private const PASS = 'testpass';

    public static function setUpBeforeClass(): void
    {
        // self-signed cert + RSA klíč → P12 (+ samostatný cert PEM pro verify).
        // openssl ext potřebuje openssl.cnf — když ho prostředí nemá (typicky holý
        // Windows bez OPENSSL_CONF), gen vrátí false → celá třída se skipne místo
        // tvrdé chyby (reálnou verifikaci pokrývá Linux CI).
        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $dn = ['commonName' => 'Test Signer', 'countryName' => 'CZ'];
        $csr = $pkey ? openssl_csr_new($dn, $pkey) : false;
        $x509 = $csr ? openssl_csr_sign($csr, null, $pkey, 365) : false;
        if ($x509 === false) {
            self::$skipReason = 'openssl ext neumí vygenerovat test cert (chybí openssl.cnf).';
            return;
        }
        $p12 = '';
        openssl_pkcs12_export($x509, $p12, $pkey, self::PASS);
        self::$p12Path = (string) tempnam(sys_get_temp_dir(), 'p12-');
        file_put_contents(self::$p12Path, $p12);
        self::$certPemPath = (string) tempnam(sys_get_temp_dir(), 'crt-');
        openssl_x509_export($x509, $pem);
        file_put_contents(self::$certPemPath, $pem);
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== null) {
            self::markTestSkipped(self::$skipReason);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$p12Path !== '') { @unlink(self::$p12Path); }
        if (self::$certPemPath !== '') { @unlink(self::$certPemPath); }
    }

    private function signer(): PdfSigner
    {
        // Prázdný Config stačí — testy používají plaintext heslo (bez enc:v1: prefixu),
        // takže SecretEncryption::decrypt() vrací as-is a šifrovací klíč se nevyžaduje.
        // Tím test nezávisí na cfg.php (v CI checkoutu neexistuje).
        return new PdfSigner(new SecretEncryption(new Config([])));
    }

    private function cfg(?string $tsa = null): SigningConfig
    {
        // passwordEnc = plaintext (bez enc:v1: prefixu → decrypt vrátí as-is)
        return new SigningConfig(self::$p12Path, self::PASS, $tsa, 'Faktura');
    }

    private function makeMpdf(): string
    {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => sys_get_temp_dir(),
            'default_font' => 'dejavusans', // jiné fonty smazal cleanup-mpdf-fonts
        ]);
        $mpdf->WriteHTML('<p style="font-family:dejavusans">Test faktura — položka 1 000 Kč</p>');
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    public function testSignsRealMpdfOutput(): void
    {
        $orig = $this->makeMpdf();
        self::assertStringStartsWith('%PDF-1.4', $orig);

        $signed = $this->signer()->sign($orig, $this->cfg());

        self::assertStringContainsString('/Type /Sig', $signed, 'chybí signature dictionary');
        self::assertStringContainsString('/ByteRange [0 ', $signed, 'chybí ByteRange');
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $signed);
        self::assertStringContainsString('/AcroForm', $signed, 'chybí AcroForm');
        // původní obsah PDF zůstal beze změny (incremental update)
        self::assertStringStartsWith($orig, $signed, 'incremental update změnil originál');
        // ByteRange už nesmí obsahovat placeholder mezery
        self::assertDoesNotMatchRegularExpression('/\/ByteRange \[0 {10,}/', $signed);
    }

    public function testByteRangeCoversWholeFileExceptContents(): void
    {
        $signed = $this->signer()->sign($this->makeMpdf(), $this->cfg());
        preg_match('/\/ByteRange \[0 +(\d+) +(\d+) +(\d+)\]/', $signed, $m);
        self::assertNotEmpty($m, 'ByteRange se nepodařilo přečíst');
        [$x1, $x2, $x3] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        // x2 (start druhého úseku) + x3 (jeho délka) = délka celého souboru
        self::assertSame(strlen($signed), $x2 + $x3, 'ByteRange nepokrývá konec souboru');
        // mezi x1 a x2 je jen hex obsah Contents
        // gap [x1,x2) je <hex> VČETNĚ závorek (standardní konvence) → hex je uvnitř
        $gap = substr($signed, $x1, $x2 - $x1);
        self::assertSame('<', $gap[0], 'gap nezačíná <');
        self::assertSame('>', $gap[strlen($gap) - 1], 'gap nekončí >');
        self::assertMatchesRegularExpression('/^<[0-9a-f]+>$/', $gap, 'mezera ByteRange není <hex>');
    }

    public function testPkcs7VerifiesAgainstSignedBytes(): void
    {
        // Křížová verifikace běží přes openssl CLI. Když není v PATH, test přeskoč
        // (ne fail) — logiku ByteRange/CMS pokrývají testy výše.
        exec('openssl version 2>&1', $vOut, $vRc);
        if ($vRc !== 0) {
            self::markTestSkipped('openssl CLI není dostupné — křížová verifikace přeskočena.');
        }

        $signed = $this->signer()->sign($this->makeMpdf(), $this->cfg());
        preg_match('/\/ByteRange \[0 +(\d+) +(\d+) +(\d+)\]/', $signed, $m);
        [$x1, $x2, $x3] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $signedBytes = substr($signed, 0, $x1) . substr($signed, $x2, $x3);

        // vytáhni hex Contents → DER. Délku NEČTI z paddingu (rtrim by sebral i platné
        // koncové \x00) — přečti ji z ASN.1 hlavičky: 0x30 0x82 hi lo => obsah, total = 4+len.
        // hex Contents leží na [x1, x2) (x1 = ByteRange[1] = první hex bajt po '<', x2 = '>')
        // hex je MEZI závorkami: [x1+1, x2-1)
        $raw = hex2bin(substr($signed, $x1 + 1, $x2 - $x1 - 2));
        self::assertSame("\x30\x82", substr($raw, 0, 2), 'DER nezačíná SEQUENCE (0x30 0x82)');
        $len = (ord($raw[2]) << 8) | ord($raw[3]);
        $der = substr($raw, 0, 4 + $len);

        $derFile = (string) tempnam(sys_get_temp_dir(), 'der-');
        $dataFile = (string) tempnam(sys_get_temp_dir(), 'dat-');
        $nullSink = (string) tempnam(sys_get_temp_dir(), 'nul-'); // portabilní /dev/null (Windows ho nemá)
        file_put_contents($derFile, $der);
        file_put_contents($dataFile, $signedBytes);

        // openssl cms -verify (CLI) — spolehlivé pro detached DER CMS proti datům.
        // -noverify: self-signed test cert (neřešíme chain).
        $cmd = sprintf(
            'openssl cms -verify -in %s -inform DER -content %s -certfile %s '
            . '-noverify -binary -out %s 2>&1',
            escapeshellarg($derFile), escapeshellarg($dataFile), escapeshellarg(self::$certPemPath),
            escapeshellarg($nullSink)
        );
        exec($cmd, $out, $rc);
        @unlink($derFile); @unlink($dataFile); @unlink($nullSink);
        self::assertSame(0, $rc, 'CMS podpis neověřen proti ByteRange datům: ' . implode("\n", $out));
    }
}
