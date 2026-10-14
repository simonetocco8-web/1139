<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;

/**
 * @property string $amount
 * @property CarbonImmutable $effective_on
 */
class PlanVersion extends ImmutableRecord
{
    protected $table = 'capitation_plan_versions';

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class, 'effective_on' => CalendarDate::class, 'amount' => 'decimal:2', 'due_days' => 'integer', 'is_active' => 'boolean'];
    }
}
