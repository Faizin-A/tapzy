<?php

namespace App\Services\Store;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StoreManagementService
{
    private const MAX_SLUG_LENGTH = 150;

    private const MAX_SLUG_ATTEMPTS = 100;

    private const CREATE_ATTEMPTS = 5;

    private const CREATE_FIELDS = [
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
    ];

    private const UPDATE_FIELDS = [
        'name',
        'description',
        'phone',
        'address',
        'google_maps_url',
        'avg_service_time',
        'queue_prefix',
        'ordering_mode',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Store {
        $attributes = Arr::only($data, self::CREATE_FIELDS);
        $requestedSlug = isset($attributes['slug']) && $attributes['slug'] !== ''
            ? (string) $attributes['slug']
            : null;
        unset($attributes['slug']);

        for ($attempt = 1; $attempt <= self::CREATE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $attributes,
                    $requestedSlug,
                    $actor,
                    $ipAddress,
                    $userAgent,
                ): Store {
                    $slug = $requestedSlug !== null
                        ? $this->assertRequestedSlugAvailable($requestedSlug)
                        : $this->generateUniqueSlug((string) $attributes['name']);

                    $store = Store::create([
                        ...$attributes,
                        'slug' => $slug,
                    ]);
                    $store->refresh();

                    $this->auditLogService->record(
                        AuditLogService::STORE_CREATED,
                        $store,
                        $actor,
                        [],
                        $this->auditLogService->storeSnapshot($store),
                        $ipAddress,
                        $userAgent,
                    );

                    return $store->fresh();
                });
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                if ($requestedSlug !== null) {
                    throw ValidationException::withMessages([
                        'slug' => 'Slug sudah digunakan.',
                    ]);
                }
            }
        }

        throw new RuntimeException('Unable to generate a unique store slug.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Store $store,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Store {
        return DB::transaction(function () use ($store, $data, $actor, $ipAddress, $userAgent): Store {
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditLogService->storeSnapshot($locked);

            $locked->fill(Arr::only($data, self::UPDATE_FIELDS));

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->save();

            $this->auditLogService->record(
                AuditLogService::STORE_UPDATED,
                $locked,
                $actor,
                $oldValues,
                $this->auditLogService->storeSnapshot($locked),
                $ipAddress,
                $userAgent,
            );

            return $locked->fresh();
        });
    }

    public function setActive(
        Store $store,
        bool $active,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Store {
        return DB::transaction(function () use ($store, $active, $actor, $ipAddress, $userAgent): Store {
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $active) {
                return $locked;
            }

            $oldValues = $this->auditLogService->storeSnapshot($locked);
            $locked->is_active = $active;
            $locked->save();

            $this->auditLogService->record(
                $active ? AuditLogService::STORE_ACTIVATED : AuditLogService::STORE_DEACTIVATED,
                $locked,
                $actor,
                $oldValues,
                $this->auditLogService->storeSnapshot($locked),
                $ipAddress,
                $userAgent,
            );

            return $locked->fresh();
        });
    }

    public function setExpiry(
        Store $store,
        DateTimeInterface|string $expiresAt,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Store {
        $value = $expiresAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($expiresAt)
            : CarbonImmutable::parse($expiresAt, (string) config('app.timezone'));

        if ($value->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'expires_at' => 'Tanggal kedaluwarsa harus di masa depan.',
            ]);
        }

        return DB::transaction(function () use ($store, $value, $actor, $ipAddress, $userAgent): Store {
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();
            $currentExpiry = $locked->expires_at?->utc();

            if ($currentExpiry !== null && $currentExpiry->equalTo($value->utc())) {
                return $locked;
            }

            $oldValues = $this->auditLogService->storeSnapshot($locked);

            $locked->expires_at = $value;
            $locked->save();

            $this->auditLogService->record(
                AuditLogService::STORE_EXPIRY_UPDATED,
                $locked,
                $actor,
                $oldValues,
                $this->auditLogService->storeSnapshot($locked),
                $ipAddress,
                $userAgent,
            );

            return $locked->fresh();
        });
    }

    public function delete(
        Store $store,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        DB::transaction(function () use ($store, $actor, $ipAddress, $userAgent): void {
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditLogService->storeSnapshot($locked);

            $locked->delete();

            $this->auditLogService->record(
                AuditLogService::STORE_DELETED,
                $locked,
                $actor,
                $oldValues,
                ['deleted_at' => $locked->deleted_at?->utc()->toIso8601String()],
                $ipAddress,
                $userAgent,
            );
        });
    }

    private function assertRequestedSlugAvailable(string $slug): string
    {
        $slug = Str::slug($slug);

        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => 'Slug tidak valid.',
            ]);
        }

        $slug = Str::limit($slug, self::MAX_SLUG_LENGTH, '');

        if (Store::withTrashed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'Slug sudah digunakan.',
            ]);
        }

        return $slug;
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'store';
        $base = Str::limit($base, self::MAX_SLUG_LENGTH - 10, '');

        for ($suffix = 1; $suffix <= self::MAX_SLUG_ATTEMPTS; $suffix++) {
            $candidate = $suffix === 1 ? $base : $base.'-'.$suffix;

            if (! Store::withTrashed()->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique store slug.');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || ($exception->errorInfo[1] ?? null) === 1062;
    }
}
