<?php

namespace App\Domains\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class VerifiedSession
{
    public static function user(): User
    {
        $authenticated = Auth::user();
        abort_unless($authenticated instanceof User, 401, 'Accesso riservato.', ['X-Session-Expired' => '1']);
        $user = User::query()->find($authenticated->id);
        $verifiedAt = session('auth.second_factor_verified_at');
        $activityAt = session('auth.last_activity_at', $verifiedAt);
        $valid = $user !== null && $user->is_active && $user->activated_at !== null
            && $user->email_verified_at !== null
            && session('auth.second_factor_user_id') === $user->id
            && session('auth.session_version') === $user->session_version
            && is_int($verifiedAt) && $verifiedAt <= now('UTC')->getTimestamp()
            && is_int($activityAt) && $activityAt <= now('UTC')->getTimestamp()
            && $activityAt >= $verifiedAt
            && $activityAt > now('UTC')->getTimestamp() - ((int) config('session.lifetime', 30) * 60);
        if (! $valid) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            abort(403, 'Sessione non valida. Accedi nuovamente con secondo fattore.', ['X-Session-Expired' => '1']);
        }

        return $user;
    }

    public static function recent(): User
    {
        $user = self::user();
        if (session('auth.second_factor_verified_at') <= now('UTC')->getTimestamp() - 300) {
            throw new RecentVerificationRequired;
        }

        return $user;
    }
}
