<?php

namespace App\Services\QrCode;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

class QrCodeService
{
    public const DEFAULT_SIZE = 300;

    public const DEFAULT_MARGIN = 2;

    /**
     * Generate an SVG QR code and its base64 data URI for the given content.
     *
     * @return array{format: string, svg: string, data_uri: string}
     */
    public function generate(string $content, int $size = self::DEFAULT_SIZE, int $margin = self::DEFAULT_MARGIN): array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            throw new InvalidArgumentException('QR code content cannot be empty.');
        }

        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd
        );

        $writer = new Writer($renderer);
        $svg = $writer->writeString($trimmed, 'UTF-8', ErrorCorrectionLevel::M());

        $dataUri = 'data:image/svg+xml;base64,'.base64_encode($svg);

        return [
            'format' => 'svg',
            'svg' => $svg,
            'data_uri' => $dataUri,
        ];
    }
}
