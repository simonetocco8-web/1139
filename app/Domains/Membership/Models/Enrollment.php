<?php

namespace App\Domains\Membership\Models;

use App\Domains\Membership\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
        static::saving(function (self $model): void {
            if (in_array($model->status, ['suspended', 'ended'], true)) {
                $model->administrative_exemption = true;
                $model->exemption_reason ??= 'Esenzione prevista dallo stato amministrativo.';
            }
        });
        static::saved(function (self $model): void {
            $open = DB::table('member_exemption_periods')->where('enrollment_id', $model->id)->where('lodge_id', $model->lodge_id)->whereNull('ends_on');
            if ($model->administrative_exemption && ! $open->exists()) {
                $start = $model->wasRecentlyCreated ? $model->starts_on->toDateString() : now()->toDateString();
                if ($model->status === 'ended' && $model->ends_on !== null) {
                    $start = $model->ends_on->addDay()->toDateString();
                }
                // Reopening exemption on the same day reuses that day's interval.
                DB::table('member_exemption_periods')->updateOrInsert(['enrollment_id' => $model->id, 'starts_on' => $start], ['lodge_id' => $model->lodge_id, 'ends_on' => null, 'created_at' => now('UTC')]);
            } elseif (! $model->administrative_exemption) {
                // An empty interval is valid when exemption is toggled within a day.
                $open->update(['ends_on' => now()->subDay()->toDateString()]);
            }
        });
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
