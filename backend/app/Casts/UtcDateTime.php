<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Persist DATETIME values in UTC while exposing them in the application timezone.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
class UtcDateTime implements CastsAttributes
{
    private const DATABASE_FORMAT = 'Y-m-d H:i:s';

    private const STORAGE_TIMEZONE = 'UTC';

    /**
     * Transform a stored UTC value into the application timezone.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, self::STORAGE_TIMEZONE)
            ->setTimezone((string) config('app.timezone'));
    }

    /**
     * Transform a local input value into a UTC database value.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->toUtc($value)->format(self::DATABASE_FORMAT);
    }

    private function toUtc(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return $value->toImmutable()->utc();
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return CarbonImmutable::parse((string) $value, (string) config('app.timezone'))->utc();
    }
}
