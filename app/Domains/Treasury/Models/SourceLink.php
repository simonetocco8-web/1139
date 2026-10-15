<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;

class SourceLink extends ImmutableRecord
{
    protected $table = 'treasury_source_links';

    protected function casts(): array
    {
        return [];
    }
}
