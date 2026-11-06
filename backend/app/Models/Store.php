<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
])]
class Store extends Model
{
    use SoftDeletes;

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
