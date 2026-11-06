<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class AuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'user_id' => 'integer',
            'entity_id' => 'integer',
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit logs are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit logs are append-only and cannot be deleted through normal model operations.');
        });
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Audit logs are append-only and cannot be updated.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('Audit logs are append-only and cannot be deleted through normal model operations.');
    }

    public function restore(): never
    {
        throw new LogicException('Audit logs do not use soft deletes and cannot be restored.');
    }

    /**
     * Get the affected store, including a soft-deleted store.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }

    /**
     * Get the user who performed the action, including a soft-deleted user.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
