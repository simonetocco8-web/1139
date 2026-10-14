<?php

namespace App\Domains\Capitations;

use App\Domains\Capitations\Models\Plan;
use Carbon\CarbonImmutable;

final class Calendar
{
    public const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    public static function start(Plan $plan, int $cycle): CarbonImmutable
    {
        return $plan->starts_on->addMonthsNoOverflow(self::MONTHS[$plan->periodicity] * $cycle);
    }

    public static function boundary(Plan $plan, string $date): bool
    {
        $d = CarbonImmutable::parse($date, 'Europe/Rome');
        $months = ($d->year - $plan->starts_on->year) * 12 + $d->month - $plan->starts_on->month;

        return $months >= 0 && $months % self::MONTHS[$plan->periodicity] === 0 && self::start($plan, intdiv($months, self::MONTHS[$plan->periodicity]))->toDateString() === $date;
    }

    public static function next(Plan $plan): string
    {
        for ($i = 0; $i < 2400; $i++) {
            $s = self::start($plan, $i);
            if ($s->greaterThan(now()->startOfDay())) {
                return $s->toDateString();
            }
        } throw new \LogicException('Calendario fuori intervallo.');
    }
}
