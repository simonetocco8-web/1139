<?php

namespace App\Domains\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class VerifiedSession
{
    public static function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401, 'Accesso riservato.');
        $verifiedAt = session('auth.second_factor_verified_at');
        abort_unless(
            session('auth.second_factor_user_id') === $user->id
            && is_int($verifiedAt)
            && $verifiedAt <= time()
            && $verifiedAt > time() - ((int) config('session.lifetime', 30) * 60),
            403,
            'È richiesta una sessione verificata con secondo fattore.',
        );

        return $user;
    }
}
