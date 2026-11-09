<?php

namespace App\Http\Resources;

use App\Models\NfcCard;
use Illuminate\Http\Request;

class NfcCardCreatedResource extends NfcCardResource
{
    /**
     * @param  array{format: string, svg: string, data_uri: string}|null  $qrCode
     */
    public function __construct(
        NfcCard $resource,
        private readonly string $publicToken,
        private readonly ?array $qrCode = null,
        private readonly ?string $publicUrl = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $publicUrl = $this->publicUrl ?? url('/api/v1/public/nfc/'.$this->publicToken);

        $payload = [
            ...parent::toArray($request),
            'public_token' => $this->publicToken,
            'public_url' => $publicUrl,
        ];

        if ($this->qrCode !== null) {
            $payload['qr_code'] = $this->qrCode;
        }

        return $payload;
    }
}
