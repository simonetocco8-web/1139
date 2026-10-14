<?php

namespace App\Domains\Membership\Models;

use App\Domains\Identity\UtcDateTime;
use Illuminate\Database\Eloquent\Model;

/** @property array<string,mixed> $snapshot */
class MemberHistory extends Model
{
    protected $table = 'member_history';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Storico immutabile.'));
        static::deleting(fn () => throw new \LogicException('Storico immutabile.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'occurred_at' => UtcDateTime::class];
    }
}
