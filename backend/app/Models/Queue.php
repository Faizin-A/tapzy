<?php

namespace App\Models;

use App\Models\Concerns\ScopesToCurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Queue ticket. Queue creation logic must validate that its store, session, and
 * optional card belong to the same tenant, enforce the source/card rules, and
 * reject new tickets for closed sessions. Foreign keys do not enforce these rules.
 */
#[Fillable(['customer_name', 'customer_phone', 'notes'])]
#[Hidden(['access_token_hash', 'access_token_ciphertext', 'idempotency_key_hash', 'request_payload_hash'])]
class Queue extends Model
{
    use ScopesToCurrentTenant;

    protected function casts(): array
    {
        return [
            'queue_number' => 'integer',
            'called_at' => 'datetime',
            'served_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Get the store that owns the queue.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the session containing the queue.
     *
     * @return BelongsTo<QueueSession, $this>
     */
    public function queueSession(): BelongsTo
    {
        return $this->belongsTo(QueueSession::class);
    }

    /**
     * Get the NFC card used to create the queue, if any.
     *
     * @return BelongsTo<NfcCard, $this>
     */
    public function nfcCard(): BelongsTo
    {
        return $this->belongsTo(NfcCard::class);
    }

    /**
     * Get the optional order attached to the queue.
     *
     * @return HasOne<Order, $this>
     */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Get the status history entries for the queue.
     *
     * @return HasMany<QueueStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(QueueStatusHistory::class);
    }
}
