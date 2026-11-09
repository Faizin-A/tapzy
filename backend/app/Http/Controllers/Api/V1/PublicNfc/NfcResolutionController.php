<?php

namespace App\Http\Controllers\Api\V1\PublicNfc;

use App\Http\Controllers\Controller;
use App\Http\Resources\NfcResolutionResource;
use App\Models\NfcCard;
use App\Models\Scopes\TenantScope;
use App\Services\NfcCard\NfcTokenGenerator;

class NfcResolutionController extends Controller
{
    public function show(string $token, NfcTokenGenerator $tokenGenerator): NfcResolutionResource
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            abort(404);
        }

        $card = NfcCard::withoutGlobalScope(TenantScope::class)
            ->with('store')
            ->where('public_token_hash', $tokenGenerator->hash($token))
            ->first();

        if ($card === null || ! $card->isOperational()) {
            abort(404);
        }

        $card->forceFill(['last_used_at' => now()])->saveQuietly();

        return NfcResolutionResource::make($card);
    }
}
