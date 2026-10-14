<?php

namespace App\Domains\Membership\Models;

use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 */
class Enrollment extends Model
{
    protected $table = 'member_enrollments';

    protected $guarded = ['id', 'lodge_id', 'person_id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Le iscrizioni storiche non si cancellano.'));
        static::updating(function (self $model): void {
            if ($model->getOriginal('status') === 'ended' || $model->isDirty(['lodge_id', 'person_id'])) {
                throw new \LogicException('Iscrizione conclusa o identificativi immutabili.');
            }
        });
    }

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'person_id' => 'integer', 'version' => 'integer', 'starts_on' => CalendarDate::class, 'ends_on' => CalendarDate::class, 'administrative_exemption' => 'boolean', 'exemption_reason' => 'encrypted'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
