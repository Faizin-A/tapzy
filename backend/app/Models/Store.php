<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'description',
    'phone',
    'address',
    'google_maps_url',
    'avg_service_time',
    'queue_prefix',
    'ordering_mode',
    'is_active',
    'expires_at',
])]
class Store extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'avg_service_time' => 'integer',
            'is_active' => 'boolean',
            'expires_at' => UtcDateTime::class,
        ];
    }

    /**
     * Limit the query to stores that have not passed their expiry date.
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now()->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * Limit the query to stores whose expiry date has passed.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('expires_at', '<=', now()->utc()->format('Y-m-d H:i:s'))
                ->orWhereNull('expires_at');
        });
    }

    /**
     * Limit the query to stores expiring within the given number of days.
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query
            ->where('expires_at', '>', now()->utc()->format('Y-m-d H:i:s'))
            ->where('expires_at', '<=', now()->addDays($days)->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * Determine whether the store has passed its expiry date.
     *
     * A missing expiry is treated as expired (fail-closed).
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->lessThanOrEqualTo(now());
    }

    /**
     * Determine whether the store can be used by merchants.
     */
    public function isOperational(): bool
    {
        return ! $this->trashed() && $this->is_active && ! $this->isExpired();
    }

    /**
     * Determine whether the store expires within the given number of days.
     */
    public function isExpiringSoon(int $days = 7): bool
    {
        if ($this->expires_at === null || $this->isExpired()) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo(now()->addDays($days));
    }

    /**
     * Get the users assigned to the store.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the NFC cards assigned to the store.
     *
     * @return HasMany<NfcCard, $this>
     */
    public function nfcCards(): HasMany
    {
        return $this->hasMany(NfcCard::class);
    }

    /**
     * Get the queue sessions opened by the store.
     *
     * @return HasMany<QueueSession, $this>
     */
    public function queueSessions(): HasMany
    {
        return $this->hasMany(QueueSession::class);
    }

    /**
     * Get the queues belonging to the store.
     *
     * @return HasMany<Queue, $this>
     */
    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    /**
     * Get the products owned by the store.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the orders received by the store.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get audit logs associated with the store.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
