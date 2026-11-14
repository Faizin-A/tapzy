<?php

namespace App\Services\Customer;

use App\Models\NfcCard;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Services\NfcCard\NfcTokenGenerator;

/**
 * Resolves a public NFC/QR token to an operational store for public customer
 * endpoints. Unlike NfcResolutionController (Stage 3), this service does NOT
 * update last_used_at because customer polling endpoints are called repeatedly
 * and should not pollute the card's last physical tap timestamp.
 */
class PublicStoreResolutionService
{
    public function __construct(
        private readonly NfcTokenGenerator $tokenGenerator,
    ) {}

    /**
     * Validate the token format, look up the card by its hash, verify that
     * both the card and its owning store are operationally active, and return
     * the resolved store. Returns null on any failure (caller should abort 404).
     *
     * @return array{card: NfcCard, store: Store}|null
     */
    public function resolve(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        $card = NfcCard::withoutGlobalScope(TenantScope::class)
            ->with('store')
            ->where('public_token_hash', $this->tokenGenerator->hash($token))
            ->first();

        if ($card === null || ! $card->isOperational()) {
            return null;
        }

        /** @var Store $store */
        $store = $card->store;

        return ['card' => $card, 'store' => $store];
    }
}
