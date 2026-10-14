<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class OrganizationalModel extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('La cancellazione non è consentita: disattivare la voce.'));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'obedience_id' => 'integer', 'parent_id' => 'integer', 'rite_id' => 'integer'];
    }
}
