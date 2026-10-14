<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;

/**
 * @property string $amount
 */
class Correction extends ImmutableRecord
{
    protected $table = 'capitation_corrections';

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class, 'amount' => 'decimal:2', 'reason' => 'encrypted'];
    }
}
