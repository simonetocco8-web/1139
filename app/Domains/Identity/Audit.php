<?php

namespace App\Domains\Identity;

use Illuminate\Support\Facades\DB;

final class Audit
{
    /** @param array<string, mixed> $metadata */
    public static function record(string $event, ?int $actor = null, ?int $lodge = null, ?int $subject = null, array $metadata = []): void
    {
        DB::table('identity_events')->insert(['event' => $event, 'actor_id' => $actor, 'lodge_id' => $lodge, 'subject_user_id' => $subject, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')]);
    }
}
