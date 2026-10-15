<?php

namespace App\Domains\Levies\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $effective_on
 * @property CarbonImmutable|null $ends_on
 * @property string $amount */
class Condition extends ImmutableRecord
{
    protected $table = 'levy_conditions';

    protected function casts(): array
    {
        return ['effective_on' => CalendarDate::class, 'ends_on' => CalendarDate::class, 'amount' => 'decimal:2', 'is_active' => 'boolean', 'reason' => 'encrypted', 'created_at' => UtcDateTime::class];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Lodge, $this> */
    public function lodge(): BelongsTo
    {
        return $this->belongsTo(Lodge::class);
    }

    /** @return BelongsTo<Structure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }
}
