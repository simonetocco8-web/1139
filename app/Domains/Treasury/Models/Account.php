<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $opening_amount
 * @property CarbonImmutable $opened_on */
class Account extends ImmutableRecord
{
    protected $table = 'treasury_accounts';

    protected function casts(): array
    {
        return ['iban' => 'encrypted', 'opening_amount' => 'decimal:2', 'opened_on' => CalendarDate::class, 'created_at' => UtcDateTime::class];
    }

    /** @return HasMany<Line,$this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Line::class);
    }
}
