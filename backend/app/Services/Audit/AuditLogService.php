<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\NfcCard;
use App\Models\Product;
use App\Models\QueueSession;
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

    public const PRODUCT_CREATED = 'product.created';

    public const PRODUCT_UPDATED = 'product.updated';

    public const PRODUCT_DELETED = 'product.deleted';

    public const PRODUCT_STATUS_UPDATED = 'product.status_updated';

    public const QUEUE_SESSION_OPENED = 'queue_session.opened';

    public const QUEUE_SESSION_CLOSED = 'queue_session.closed';

    public const QUEUE_SESSION_RESET = 'queue_session.reset';

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
     * Append a product audit log entry.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordProduct(
        string $action,
        Product $product,
        User $actor,
        array $oldValues = [],
        array $newValues = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return $this->recordEntity(
            $action,
            (int) $product->store_id,
            Product::class,
            (int) $product->getKey(),
            $actor,
            $oldValues,
            $newValues,
            $ipAddress,
            $userAgent,
        );
    }

    /** @return array<string, mixed> */
    public function productSnapshot(Product $product): array
    {
        return [
            'name' => $product->name,
            'description' => $product->description,
            'price' => (string) $product->price,
            'category' => $product->category,
            'image_url' => $product->image_url,
            'is_available' => $product->is_available,
            'is_active' => $product->is_active,
            'deleted_at' => $product->deleted_at?->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function queueSessionSnapshot(QueueSession $session): array
    {
        return [
            'session_code' => $session->session_code,
            'session_date' => $session->session_date?->toDateString(),
            'last_queue_number' => $session->last_queue_number,
            'status' => $session->status,
            'opened_at' => $session->opened_at?->utc()->toIso8601String(),
            'closed_at' => $session->closed_at?->utc()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $oldValues @param array<string, mixed> $newValues */
    public function recordQueueSession(
        string $action,
        QueueSession $session,
        User $actor,
        array $oldValues = [],
        array $newValues = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return $this->recordEntity(
            $action,
            (int) $session->store_id,
            QueueSession::class,
            (int) $session->getKey(),
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
