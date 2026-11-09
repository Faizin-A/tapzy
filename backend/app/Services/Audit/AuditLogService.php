<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\NfcCard;
use App\Models\Store;
use App\Models\User;

class AuditLogService
{
    public const STORE_CREATED = 'store.created';

    public const STORE_UPDATED = 'store.updated';

    public const STORE_ACTIVATED = 'store.activated';

    public const STORE_DEACTIVATED = 'store.deactivated';

    public const STORE_EXPIRY_UPDATED = 'store.expiry_updated';

    public const STORE_DELETED = 'store.deleted';

    public const NFC_CARD_CREATED = 'nfc_card.created';

    public const NFC_CARD_UPDATED = 'nfc_card.updated';

    public const NFC_CARD_ACTIVATED = 'nfc_card.activated';

    public const NFC_CARD_DEACTIVATED = 'nfc_card.deactivated';

    public const NFC_CARD_DELETED = 'nfc_card.deleted';

    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'remember_token',
        'api_key',
        'secret',
        'authorization',
        'card_token',
        'card_token_hash',
        'nfc_token',
        'nfc_token_hash',
        'public_token',
        'public_token_hash',
        'nfc_public_token',
        'nfc_public_token_hash',
    ];

    /**
     * Build a whitelisted, UTC-normalised snapshot of a store.
     *
     * @return array<string, mixed>
     */
    public function storeSnapshot(Store $store): array
    {
        return [
            'name' => $store->name,
            'slug' => $store->slug,
            'description' => $store->description,
            'phone' => $store->phone,
            'address' => $store->address,
            'google_maps_url' => $store->google_maps_url,
            'avg_service_time' => $store->avg_service_time,
            'queue_prefix' => $store->queue_prefix,
            'ordering_mode' => $store->ordering_mode,
            'is_active' => $store->is_active,
            'expires_at' => $store->expires_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * Build a whitelisted, UTC-normalised snapshot of an NFC card.
     *
     * @return array<string, mixed>
     */
    public function nfcCardSnapshot(NfcCard $card): array
    {
        return [
            'store_id' => $card->store_id,
            'card_uid' => $card->card_uid,
            'label' => $card->label,
            'type' => $card->type,
            'is_active' => $card->is_active,
            'last_used_at' => $card->last_used_at?->utc()->toIso8601String(),
            'activated_at' => $card->activated_at?->utc()->toIso8601String(),
            'deactivated_at' => $card->deactivated_at?->utc()->toIso8601String(),
            'deleted_at' => $card->deleted_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * Append a store audit log entry.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $action,
        Store $store,
        User $actor,
        array $oldValues = [],
        array $newValues = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return $this->recordEntity(
            $action,
            (int) $store->getKey(),
            Store::class,
            (int) $store->getKey(),
            $actor,
            $oldValues,
            $newValues,
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * Append an NFC card audit log entry.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordNfcCard(
        string $action,
        NfcCard $card,
        User $actor,
        array $oldValues = [],
        array $newValues = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return $this->recordEntity(
            $action,
            (int) $card->store_id,
            NfcCard::class,
            (int) $card->getKey(),
            $actor,
            $oldValues,
            $newValues,
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function recordEntity(
        string $action,
        int $storeId,
        string $entityType,
        int $entityId,
        User $actor,
        array $oldValues = [],
        array $newValues = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        $log = new AuditLog;
        $log->store_id = $storeId;
        $log->user_id = $actor->getKey();
        $log->action = $action;
        $log->entity_type = $entityType;
        $log->entity_id = $entityId;
        $log->old_values = $oldValues === [] ? null : $this->sanitize($oldValues);
        $log->new_values = $newValues === [] ? null : $this->sanitize($newValues);
        $log->ip_address = $ipAddress;
        $log->user_agent = $userAgent;
        $log->save();

        return $log;
    }

    /**
     * Recursively redact sensitive keys before persisting an audit payload.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function sanitize(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->sanitize($value);
            }
        }

        return $values;
    }
}
