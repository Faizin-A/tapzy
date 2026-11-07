<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\ScopesToCurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class QueueStatusHistory extends Model
{
    use ScopesToCurrentTenant;

    public $timestamps = false;

    protected static function tenantOwnerRelation(): ?string
    {
        return 'queue';
    }

    protected function casts(): array
    {
        return [
            'queue_id' => 'integer',
            'previous_status' => 'string',
            'new_status' => 'string',
            'changed_by_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Queue status history is immutable; append a new history row instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Queue status history cannot be deleted through normal model operations.');
        });
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Queue status history is immutable; append a new history row instead.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('Queue status history cannot be deleted through normal model operations.');
    }

    /**
     * Get the queue associated with the history entry.
     *
     * @return BelongsTo<Queue, $this>
     */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /**
     * Get the user who changed the status, including a soft-deleted user.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id')->withTrashed();
    }
}
