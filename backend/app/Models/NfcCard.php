<?php

namespace App\Models;

use App\Models\Concerns\ScopesToCurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'card_uid',
    'public_token_hash',
    'label',
    'type',
    'is_active',
    'last_used_at',
    'activated_at',
    'deactivated_at',
])]
class NfcCard extends Model
{
    use ScopesToCurrentTenant, SoftDeletes;

    protected $hidden = [
        'card_uid',
        'public_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the card can be used for public resolution.
     */
    public function isOperational(): bool
    {
        if ($this->trashed() || ! $this->is_active) {
            return false;
        }

        return $this->store?->isOperational() ?? false;
    }

    /**
     * Get the store that owns the card.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the queues created through the card.
     *
     * @return HasMany<Queue, $this>
     */
    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    /**
     * Get the orders associated with the card.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
