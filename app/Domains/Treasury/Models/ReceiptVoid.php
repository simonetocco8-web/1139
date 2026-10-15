<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;

class ReceiptVoid extends ImmutableRecord
{
    protected $table = 'capitation_receipt_voids';

    protected function casts(): array
    {
        return [];
    }
}
