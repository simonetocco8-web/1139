<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rite extends OrganizationalModel
{
    protected $table = 'rites';

    protected $fillable = ['code', 'name', 'is_active'];

    /** @return BelongsToMany<Obedience, $this> */
    public function obediences(): BelongsToMany
    {
        return $this->belongsToMany(Obedience::class);
    }

    /** @return HasMany<Lodge, $this> */
    public function lodges(): HasMany
    {
        return $this->hasMany(Lodge::class);
    }
}
