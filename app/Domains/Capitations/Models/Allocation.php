<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $amount
 */
class Allocation extends ImmutableRecord
{
    protected $table = 'capitation_allocations';

    /** @return BelongsTo<Installment,$this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class, 'amount' => 'decimal:2', 'reason' => 'encrypted', 'reverses_id' => 'integer'];
    }
}
