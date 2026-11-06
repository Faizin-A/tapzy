<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $validStoreRole = match ($user->role) {
                'super_admin' => $user->store_id === null,
                'merchant' => $user->store_id !== null,
                default => false,
            };

            if (! $validStoreRole) {
                throw ValidationException::withMessages([
                    'role' => 'Super admin harus tanpa toko; merchant wajib terhubung ke toko.',
                    'store_id' => 'Kombinasi peran dan toko pengguna tidak valid.',
                ]);
            }
        });
    }

    /**
     * Get the store assigned to the user.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get queue status history entries changed by the user.
     *
     * @return HasMany<QueueStatusHistory, $this>
     */
    public function queueStatusHistories(): HasMany
    {
        return $this->hasMany(QueueStatusHistory::class, 'changed_by_user_id');
    }

    /**
     * Get audit log entries created by the user.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
