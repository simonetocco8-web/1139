<?php

namespace App\Domains\Membership;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|null> */
final class CalendarDate implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $value)) {
            $value = substr($value, 0, 10);
        }

        return $this->parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->parse($value)?->format('Y-m-d');
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // Laravel has already serialized the cast value to UTC here; use the raw DATE.
        return $this->get($model, $key, $attributes[$key] ?? null, $attributes)?->format('Y-m-d');
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value->format('Y-m-d'), 'Europe/Rome') ?: null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \UnexpectedValueException('Data di calendario non valida.');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Rome');
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException('Data di calendario non valida.');
        }

        return $date;
    }
}
