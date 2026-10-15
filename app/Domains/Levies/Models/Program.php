<?php

namespace App\Domains\Levies\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use App\Domains\Organization\Models\Obedience;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on */
class Program extends ImmutableRecord
{
    protected $table = 'levy_programs';

    protected function casts(): array
    {
        return ['starts_on' => CalendarDate::class, 'ends_on' => CalendarDate::class, 'created_at' => UtcDateTime::class];
    }

    /** @return HasMany<Condition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(Condition::class);
    }

    /** @return BelongsTo<Obedience, $this> */
    public function obedience(): BelongsTo
    {
        return $this->belongsTo(Obedience::class);
    }
}
