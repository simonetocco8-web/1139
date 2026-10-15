<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $amount
 * @property CarbonImmutable $booked_on */
class StatementEntry extends ImmutableRecord
{
    protected $table = 'treasury_statement_entries';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'booked_on' => CalendarDate::class, 'description' => 'encrypted', 'reference' => 'encrypted', 'created_at' => UtcDateTime::class];
    }

    /** @return HasMany<Reconciliation,$this> */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class, 'statement_entry_id');
    }

    /** @return BelongsTo<Account,$this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
