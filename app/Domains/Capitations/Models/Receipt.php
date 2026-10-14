<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $amount
 * @property CarbonImmutable $received_on
 */
class Receipt extends ImmutableRecord
{
    protected $table = 'capitation_receipts';

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class, 'amount' => 'decimal:2', 'received_on' => CalendarDate::class];
    }

    /** @return HasMany<Allocation,$this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'receipt_id');
    }
}
