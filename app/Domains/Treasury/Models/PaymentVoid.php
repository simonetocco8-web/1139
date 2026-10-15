<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;

class PaymentVoid extends ImmutableRecord
{
    protected $table = 'levy_payment_voids';

    protected function casts(): array
    {
        return [];
    }
}
