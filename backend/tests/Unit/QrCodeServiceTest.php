<?php

namespace Tests\Unit;

use App\Services\QrCode\QrCodeService;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use DOMDocument;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QrCodeServiceTest extends TestCase
{
    private QrCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QrCodeService;
    }

    public function test_throws_exception_on_empty_content(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('QR code content cannot be empty.');

        $this->service->generate('   ');
    }

    public function test_generates_valid_svg_and_data_uri(): void
    {
        $url = 'http://localhost/api/v1/public/nfc/0123456789abcdef0123456789abcdef01234567';
        $result = $this->service->generate($url);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('format', $result);
        $this->assertArrayHasKey('svg', $result);
        $this->assertArrayHasKey('data_uri', $result);

        $this->assertSame('svg', $result['format']);
        $this->assertNotEmpty($result['svg']);
        $this->assertNotEmpty($result['data_uri']);

        // 1. Verify XML validity via DOMDocument
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($result['svg']), 'SVG should be valid XML');

        $root = $dom->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('svg', $root->nodeName);
        $this->assertSame('http://www.w3.org/2000/svg', $root->getAttribute('xmlns'));
        $this->assertSame('300', $root->getAttribute('width'));
        $this->assertSame('300', $root->getAttribute('height'));
        $this->assertTrue($root->hasAttribute('viewBox'));

        // 2. Verify graphic modules exist (path or rect)
        $paths = $dom->getElementsByTagName('path');
        $rects = $dom->getElementsByTagName('rect');
        $this->assertGreaterThan(0, $paths->length + $rects->length, 'SVG should contain QR modules');

        // 3. Verify Data URI format and round-trip equality
        $prefix = 'data:image/svg+xml;base64,';
        $this->assertStringStartsWith($prefix, $result['data_uri']);

        $base64Payload = substr($result['data_uri'], strlen($prefix));
        $decodedSvg = base64_decode($base64Payload, true);
        $this->assertNotFalse($decodedSvg);
        $this->assertSame($result['svg'], $decodedSvg, 'Decoded Data URI must match the exact SVG string byte-for-byte');
    }

    public function test_custom_size_and_margin(): void
    {
        $url = 'https://example.com/test';
        $result = $this->service->generate($url, 450, 4);

        $dom = new DOMDocument;
        $dom->loadXML($result['svg']);

        $root = $dom->documentElement;
        $this->assertSame('450', $root->getAttribute('width'));
        $this->assertSame('450', $root->getAttribute('height'));
    }

    public function test_qr_matrix_encoder_verification(): void
    {
        $url = 'http://localhost/api/v1/public/nfc/a1b2c3d4e5f67890123456789abcdef012345678';

        // Deterministic matrix encoding verification using BaconQrCode Encoder
        $qrCode = Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8');
        $matrix = $qrCode->getMatrix();

        $this->assertNotNull($matrix);
        $this->assertGreaterThan(20, $matrix->getWidth());
        $this->assertSame($matrix->getWidth(), $matrix->getHeight(), 'QR Matrix must be square');
        $this->assertSame(ErrorCorrectionLevel::M(), $qrCode->getErrorCorrectionLevel());

        // Verify that the generated SVG dimensions reflect the matrix structure
        $result = $this->service->generate($url);
        $this->assertStringContainsString('viewBox="0 0', $result['svg']);
    }
}
