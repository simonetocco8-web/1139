<?php

namespace App\Domains\Levies\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use App\Domains\Treasury\Models\PaymentVoid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** @return HasOne<PaymentVoid,$this> */
    public function voidRecord(): HasOne
    {
        return $this->hasOne(PaymentVoid::class, 'payment_id');
    }
}
