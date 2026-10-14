<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Structure extends OrganizationalModel
{
    protected $table = 'structures';

    protected $fillable = ['obedience_id', 'parent_id', 'level', 'name', 'email', 'phone', 'address', 'iban', 'is_active'];

    /** @return BelongsTo<Obedience, $this> */
    public function obedience(): BelongsTo
    {
        return $this->belongsTo(Obedience::class);
    }

    /** @return BelongsTo<Structure, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'parent_id');
    }

    /** @return HasMany<Structure, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Structure::class, 'parent_id');
    }

    /** @return HasMany<TerritorialAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TerritorialAssignment::class);
    }
}
