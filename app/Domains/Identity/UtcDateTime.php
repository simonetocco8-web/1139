<?php

namespace App\Domains\Identity;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|null> */
final class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $this->parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->parse($value)?->format('Y-m-d H:i:s.u');
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw new \UnexpectedValueException('Data non valida.');
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
