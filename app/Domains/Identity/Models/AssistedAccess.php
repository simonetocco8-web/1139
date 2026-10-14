<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\UtcDateTime;
use Illuminate\Database\Eloquent\Model;

/** @property list<string> $permissions */
class AssistedAccess extends Model
{
    protected $table = 'assisted_access_grants';

    protected $guarded = [];

    protected $hidden = ['code_digest', 'token_digest', 'payload', 'session_binding'];

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'requester_id' => 'integer', 'approved_by' => 'integer', 'reason' => 'encrypted', 'permissions' => 'array', 'approved_at' => UtcDateTime::class, 'expires_at' => UtcDateTime::class, 'revoked_at' => UtcDateTime::class, 'duration_minutes' => 'integer'];
    }
}
