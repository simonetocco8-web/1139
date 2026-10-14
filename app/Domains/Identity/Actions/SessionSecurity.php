<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Audit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SessionSecurity
{
    // Caller holds the user lock inside a transaction for credential changes.
    public function revoke(User $user, string $event = 'sessions.revoked'): void
    {
        $user->forceFill(['session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('otp_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now('UTC')]);
        Audit::record($event, $user->id, subject: $user->id);
    }
}
