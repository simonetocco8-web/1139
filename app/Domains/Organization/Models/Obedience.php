<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Obedience extends OrganizationalModel
{
    protected $table = 'obediences';

    protected $fillable = ['code', 'name', 'email', 'phone', 'address', 'is_active'];

    /** @return BelongsToMany<Rite, $this> */
    public function rites(): BelongsToMany
    {
        return $this->belongsToMany(Rite::class);
    }

    /** @return HasMany<Structure, $this> */
    public function structures(): HasMany
    {
        return $this->hasMany(Structure::class);
    }

    /** @return HasMany<Lodge, $this> */
    public function lodges(): HasMany
    {
        return $this->hasMany(Lodge::class);
    }
}
