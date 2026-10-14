<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 */
class Plan extends ImmutableRecord
{
    protected $table = 'capitation_plans';

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class, 'starts_on' => CalendarDate::class, 'ends_on' => CalendarDate::class];
    }

    /** @return HasMany<PlanVersion,$this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id');
    }
}
