<?php

namespace App\Actions\Fortify;

use App\Domains\Identity\Actions\SessionSecurity;
use App\Domains\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, mixed> $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => PasswordRules::strong()])->validate();
        DB::transaction(function () use ($user, $input): void {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->is_active || $fresh->email !== $user->email || $fresh->session_version !== $user->session_version) {
                throw ValidationException::withMessages(['email' => 'Richiesta non valida o scaduta.']);
            }
            $fresh->forceFill(['password' => Hash::make($input['password'])])->save();
            app(SessionSecurity::class)->revoke($fresh, 'password.reset');
            // Fortify's CompletePasswordReset will only rotate remember token, never log in.
            $user->setRawAttributes($fresh->getAttributes(), true);
        }, 3);
    }
}
