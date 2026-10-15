<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;

class Category extends ImmutableRecord
{
    protected $table = 'treasury_categories';

    protected function casts(): array
    {
        return [];
    }
}
