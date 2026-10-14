<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\UtcDateTime;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $payload
 * @property int|null $user_id
 */
class OtpChallenge extends Model
{
    protected $table = 'otp_challenges';

    protected $guarded = [];

    protected $hidden = ['code_digest', 'token_digest', 'payload', 'session_binding'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => UtcDateTime::class, 'last_sent_at' => UtcDateTime::class, 'consumed_at' => UtcDateTime::class, 'invalidated_at' => UtcDateTime::class, 'attempts' => 'integer', 'credential_version' => 'integer', 'user_id' => 'integer'];
    }
}
