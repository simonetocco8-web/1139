<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $amount */
class Line extends ImmutableRecord
{
    protected $table = 'treasury_lines';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Account,$this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Operation,$this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /** @return HasMany<Reconciliation,$this> */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }
}
