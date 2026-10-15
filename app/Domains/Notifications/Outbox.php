<?php

namespace App\Domains\Notifications;

use App\Domains\Identity\UtcDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable $created_at
 */
class Outbox extends Model
{
    protected $table = 'communication_outbox';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lodge_id' => 'integer', 'user_id' => 'integer', 'source_id' => 'integer', 'attempts' => 'integer', 'retry_authorized' => 'boolean', 'available_at' => UtcDateTime::class, 'expires_at' => UtcDateTime::class, 'queued_at' => UtcDateTime::class, 'claimed_at' => UtcDateTime::class, 'accepted_at' => UtcDateTime::class, 'created_at' => UtcDateTime::class];
    }
}
