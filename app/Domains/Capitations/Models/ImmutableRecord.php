<?php

namespace App\Domains\Capitations\Models;

use Illuminate\Database\Eloquent\Model;

abstract class ImmutableRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Record economico immutabile: aggiungere una nuova versione o rettifica.'));
        static::deleting(fn () => throw new \LogicException('Lo storico economico non si cancella.'));
    }
}
