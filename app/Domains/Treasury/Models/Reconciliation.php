<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Identity\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reconciliation extends Model
{
    public $timestamps = false;

    protected $guarded = ['active_entry_id', 'active_line_id'];

    protected $table = 'treasury_reconciliations';

    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'ended_at' => UtcDateTime::class, 'release_reason' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('La riconciliazione storica non si cancella.'));
        static::updating(function (self $r): void {
            if ($r->getOriginal('ended_at') !== null || ! $r->ended_at || array_diff(array_keys($r->getDirty()), ['ended_at', 'released_by', 'release_reason'])) {
                throw new \LogicException('Consentita soltanto la prima chiusura della riconciliazione.');
            }
        });
    }

    /** @return BelongsTo<Line,$this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }
}
