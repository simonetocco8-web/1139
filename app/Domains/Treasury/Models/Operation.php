<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Capitations\Models\ImmutableRecord;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Membership\CalendarDate;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property CarbonImmutable $booked_on
 * @property CarbonImmutable $created_at
 * @property Category|null $category */
class Operation extends ImmutableRecord
{
    protected $table = 'treasury_operations';

    protected function casts(): array
    {
        return ['description' => 'encrypted', 'booked_on' => CalendarDate::class, 'created_at' => UtcDateTime::class];
    }

    /** @return HasMany<Line,$this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Line::class);
    }

    /** @return HasMany<Attachment,$this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return BelongsTo<Operation,$this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'reverses_id');
    }

    /** @return HasOne<Operation,$this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(Operation::class, 'reverses_id');
    }

    /** @return BelongsTo<Category,$this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Person,$this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Structure,$this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }
}
