<?php

namespace App\Domains\Levies;

use App\Domains\Capitations\Calendar as CapitationCalendar;
use App\Domains\Levies\Models\Program;
use Carbon\CarbonImmutable;

final class Calendar
{
    public const MONTHS = CapitationCalendar::MONTHS;

    public static function start(Program $program, int $cycle): CarbonImmutable
    {
        return $program->starts_on->addMonthsNoOverflow(self::MONTHS[$program->periodicity] * $cycle);
    }

    public static function boundary(Program $program, string $date): bool
    {
        $d = CarbonImmutable::parse($date, 'Europe/Rome');
        $months = ($d->year - $program->starts_on->year) * 12 + $d->month - $program->starts_on->month;

        return $months >= 0 && $months % self::MONTHS[$program->periodicity] === 0 && self::start($program, intdiv($months, self::MONTHS[$program->periodicity]))->toDateString() === $date;
    }
}
