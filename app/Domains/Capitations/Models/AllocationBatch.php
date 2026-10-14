<?php

namespace App\Domains\Capitations\Models;

use App\Domains\Identity\UtcDateTime;

class AllocationBatch extends ImmutableRecord
{
    protected $table = 'capitation_allocation_batches';

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'plan_id' => 'integer', 'created_at' => UtcDateTime::class];
    }
}
