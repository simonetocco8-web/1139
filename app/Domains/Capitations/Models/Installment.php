<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use App\Domains\Membership\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $original_amount
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_on
 * @property array<string,mixed> $snapshot
 */
class Installment extends ImmutableRecord
{
    protected $table = 'capitation_installments';

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class, 'period_start' => CalendarDate::class, 'period_end' => CalendarDate::class, 'due_on' => CalendarDate::class, 'original_amount' => 'decimal:2', 'snapshot' => 'array'];
    }

    /** @return HasMany<Correction,$this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(Correction::class, 'installment_id');
    }

    /** @return HasMany<Allocation,$this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'installment_id');
    }

    /** @return BelongsTo<Person,$this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
