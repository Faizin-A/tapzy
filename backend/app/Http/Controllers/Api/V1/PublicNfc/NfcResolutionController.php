<?php

namespace App\Http\Controllers\Api\V1\PublicNfc;

use App\Http\Controllers\Controller;
use App\Http\Resources\NfcResolutionResource;
use App\Models\NfcCard;
use App\Models\Scopes\TenantScope;
use App\Services\NfcCard\NfcTokenGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NfcResolutionController extends Controller
{
    public function show(
        string $token,
        Request $request,
        NfcTokenGenerator $tokenGenerator
    ): NfcResolutionResource|RedirectResponse {
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

        if (! $request->expectsJson()) {
            return redirect()->route('customer.store', ['token' => $token]);
        }

        return NfcResolutionResource::make($card);
    }
}
