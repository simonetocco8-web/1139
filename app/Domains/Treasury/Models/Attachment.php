<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;

class Attachment extends ImmutableRecord
{
    protected $table = 'treasury_attachments';

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'created_at' => UtcDateTime::class];
    }
}
