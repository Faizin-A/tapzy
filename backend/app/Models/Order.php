<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Financial values, order identity, tenant ownership, and status are assigned
 * by the backend; request data must not mass-assign them.
 */
#[Fillable(['customer_name', 'notes'])]
class Order extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'total_items' => 'integer',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_price' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the store that owns the order.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the queue attached to the order, if any.
     *
     * @return BelongsTo<Queue, $this>
     */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /**
     * Get the NFC card associated with the order, if any.
     *
     * @return BelongsTo<NfcCard, $this>
     */
    public function nfcCard(): BelongsTo
    {
        return $this->belongsTo(NfcCard::class);
    }

    /**
     * Get the item rows in the order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
