<?php

namespace App\Services\NfcCard;

use Illuminate\Support\Str;

class NfcTokenGenerator
{
    public const TOKEN_LENGTH = 40;

    public function generate(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
