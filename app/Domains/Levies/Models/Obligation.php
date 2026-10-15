<?php

namespace App\Domains\Levies\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $reference_on
 * @property CarbonImmutable $due_on
 * @property string $amount
 * @property string|null $payments_sum_amount */
class Obligation extends ImmutableRecord
{
    protected $table = 'levy_obligations';

    protected function casts(): array
    {
        return ['period_start' => CalendarDate::class, 'period_end' => CalendarDate::class, 'reference_on' => CalendarDate::class, 'due_on' => CalendarDate::class, 'amount' => 'decimal:2', 'member_count' => 'integer', 'snapshot' => 'array', 'recipient_iban' => 'encrypted', 'created_at' => UtcDateTime::class];
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->whereDoesntHave('voidRecord');
    }

    /** @return BelongsTo<Lodge, $this> */
    public function lodge(): BelongsTo
    {
        return $this->belongsTo(Lodge::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Condition, $this> */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(Condition::class);
    }

    /** @return BelongsTo<TerritorialAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TerritorialAssignment::class);
    }

    /** @return BelongsTo<Structure, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'recipient_id');
    }
}
