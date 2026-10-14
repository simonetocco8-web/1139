<?php

namespace App\Domains\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lodge extends OrganizationalModel
{
    protected $table = 'lodges';

    protected $fillable = ['obedience_id', 'rite_id', 'number', 'name', 'email', 'phone', 'address', 'iban', 'is_active'];

    /** @return BelongsTo<Obedience, $this> */
    public function obedience(): BelongsTo
    {
        return $this->belongsTo(Obedience::class);
    }

    /** @return BelongsTo<Rite, $this> */
    public function rite(): BelongsTo
    {
        return $this->belongsTo(Rite::class);
    }

    /** @return HasMany<TerritorialAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TerritorialAssignment::class);
    }

    /** @return HasMany<TerritorialAssignment, $this> */
    public function currentAssignments(): HasMany
    {
        return $this->assignments()->whereNull('ended_at');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_active');
    }
}
