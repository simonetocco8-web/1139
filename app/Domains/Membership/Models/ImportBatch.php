<?php

namespace App\Domains\Membership\Models;

use App\Domains\Identity\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** @property array{rows:list<array<string,mixed>>,errors:array<int,array<string,list<string>>>} $payload */
class ImportBatch extends Model
{
    use MassPrunable;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'));
    }

    protected $table = 'member_imports';

    protected $guarded = [];

    protected $hidden = ['payload', 'token_digest', 'session_binding'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => UtcDateTime::class, 'consumed_at' => UtcDateTime::class, 'lodge_id' => 'integer', 'actor_id' => 'integer'];
    }
}
