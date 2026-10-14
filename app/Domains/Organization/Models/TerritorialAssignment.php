<?php

namespace App\Domains\Organization\Models;

use App\Domains\Identity\UtcDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritorialAssignment extends OrganizationalModel
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $assignment): void {
            if ($assignment->isDirty(['lodge_id', 'obedience_id', 'structure_id', 'level', 'structure_name', 'starts_at'])
                || $assignment->getOriginal('ended_at') !== null
                || $assignment->ended_at === null
                || CarbonImmutable::parse($assignment->ended_at)->lessThan($assignment->starts_at)) {
                throw new \LogicException('Le assegnazioni storiche sono immutabili; è consentita soltanto la prima chiusura.');
            }
        });
    }

    public $timestamps = false;

    protected $table = 'lodge_territorial_assignments';

    protected $fillable = ['lodge_id', 'obedience_id', 'structure_id', 'level', 'structure_name', 'starts_at', 'ended_at'];

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'obedience_id' => 'integer', 'structure_id' => 'integer', 'starts_at' => UtcDateTime::class, 'ended_at' => UtcDateTime::class];
    }

    /** @return BelongsTo<Structure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /** @return BelongsTo<Lodge, $this> */
    public function lodge(): BelongsTo
    {
        return $this->belongsTo(Lodge::class);
    }
}
