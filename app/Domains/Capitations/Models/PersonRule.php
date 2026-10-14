<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;

/**
 * @property string|null $amount
 * @property CarbonImmutable $effective_on
 * @property CarbonImmutable|null $ends_on
 */
class PersonRule extends ImmutableRecord
{
    protected $table = 'capitation_person_rules';

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class, 'effective_on' => CalendarDate::class, 'ends_on' => CalendarDate::class, 'amount' => 'decimal:2', 'discount_bps' => 'integer', 'reason' => 'encrypted'];
    }
}
