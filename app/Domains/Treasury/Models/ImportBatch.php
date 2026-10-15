<?php

namespace App\Domains\Treasury\Models;

use App\Domains\Identity\UtcDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** @property array{rows:list<array{input:array<string,mixed>,duplicate:bool,similar:bool}>,errors:array<int,array<string,list<string>>>} $payload
 * @property CarbonImmutable $expires_at */
class ImportBatch extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['payload', 'token_digest', 'session_digest'];

    protected $table = 'treasury_imports';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => UtcDateTime::class, 'confirmed_at' => UtcDateTime::class, 'created_at' => UtcDateTime::class];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now('UTC')->subDay());
    }
}
