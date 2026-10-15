<?php

namespace App\Domains\Levies\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $paid_on
 * @property string $amount */
class Payment extends ImmutableRecord
{
    protected $table = 'levy_payments';

    protected function casts(): array
    {
        return ['paid_on' => CalendarDate::class, 'amount' => 'decimal:2', 'created_at' => UtcDateTime::class];
    }

    /** @return BelongsTo<Obligation, $this> */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }
}
