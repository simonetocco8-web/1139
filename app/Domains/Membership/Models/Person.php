<?php

namespace App\Domains\Membership\Models;

use App\Domains\Membership\CalendarDate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $table = 'member_people';

    protected $guarded = ['id', 'lodge_id', 'user_id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Disattivare l’iscrizione; non cancellare la persona.'));
    }

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'user_id' => 'integer', 'version' => 'integer', 'phone' => 'encrypted', 'address' => 'encrypted', 'birth_date' => CalendarDate::class];
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'person_id');
    }

    /** @return HasMany<MemberHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(MemberHistory::class, 'person_id');
    }

    /** @return BelongsTo<User, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
