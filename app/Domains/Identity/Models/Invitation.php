<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\UtcDateTime;
use Illuminate\Database\Eloquent\Model;

/** @property list<string> $roles */
class Invitation extends Model
{
    protected $table = 'identity_invitations';

    protected $guarded = [];

    protected $hidden = ['code_digest', 'token_digest', 'payload', 'session_binding'];

    protected function casts(): array
    {
        return ['invited_by' => 'integer', 'roles' => 'array', 'bootstrap' => 'boolean', 'platform_admin' => 'boolean', 'expires_at' => UtcDateTime::class, 'accepted_at' => UtcDateTime::class, 'revoked_at' => UtcDateTime::class, 'lodge_id' => 'integer'];
    }
}
