<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Secret;
use App\Domains\Identity\VerifiedSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountSecurity
{
    public function __construct(private OtpService $otp, private SessionSecurity $sessions) {}

    public function password(string $password): User
    {
        $user = VerifiedSession::recent();
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'La conferma delle credenziali non è valida.']);
        }

        return $user;
    }

    public function changeContact(string $channel, string $target, string $password): void
    {
        $user = $this->password($password);
        $target = $channel === 'email' ? mb_strtolower(trim($target)) : trim($target);
        $this->target($channel, $target, $user->id);
        $this->otp->begin($user, $channel === 'email' ? 'change_email' : 'change_phone', $channel, ['target' => $target]);
    }

    public function setChannel(string $channel, string $password): void
    {
        $user = $this->password($password);
        abort_unless(in_array($channel, ['email', 'sms'], true), 422);
        abort_unless($channel === 'email' ? $user->email_verified_at !== null : ($user->phone !== null && $user->phone_verified_at !== null), 422, 'Verifica prima il recapito.');
        DB::transaction(function () use ($user, $channel): void {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->session_version === $user->session_version, 403);
            $fresh->forceFill(['otp_channel' => $channel])->save();
            $this->sessions->revoke($fresh, 'channel.changed');
        }, 3);
    }

    /** @return list<string> */
    public function recoveryCodes(string $password): array
    {
        $user = $this->password($password);

        return DB::transaction(function () use ($user): array {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->session_version === $user->session_version, 403);
            DB::table('identity_recovery_codes')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now('UTC')]);
            $codes = [];
            for ($i = 0; $i < 10; $i++) {
                $raw = bin2hex(random_bytes(16));
                $codes[] = implode('-', str_split($raw, 8));
                DB::table('identity_recovery_codes')->insert(['user_id' => $user->id, 'digest' => Secret::digest('recovery', $user->id.'|'.$raw), 'created_at' => now('UTC')]);
            }
            Audit::record('recovery_codes.rotated', $user->id);

            return $codes;
        }, 3);
    }

    public function recover(string $email, string $password, string $recoveryCode, string $channel, string $target): void
    {
        $target = $channel === 'email' ? mb_strtolower(trim($target)) : trim($target);
        $this->target($channel, $target);
        $user = $this->otp->credentials($email, $password);
        $authorized = DB::transaction(function () use ($user, $recoveryCode): ?User {
            if ($user === null) {
                return null;
            }
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($fresh->session_version !== $user->session_version || ! $fresh->is_active) {
                return null;
            }
            $digest = Secret::digest('recovery', $user->id.'|'.str_replace('-', '', mb_strtolower(trim($recoveryCode))));
            $code = DB::table('identity_recovery_codes')->where('user_id', $user->id)->where('digest', $digest)->whereNull('consumed_at')->lockForUpdate()->first();
            if ($code === null) {
                Audit::record('factor_recovery.rejected');

                return null;
            }
            DB::table('identity_recovery_codes')->where('id', $code->id)->update(['consumed_at' => now('UTC')]);
            $this->sessions->revoke($fresh, 'factor_recovery.started');

            return $fresh;
        }, 3);
        if ($authorized === null) {
            $this->otp->beginLogin(null);
        } else {
            auth()->logout();
            session()->invalidate();
            session()->regenerateToken();
            $this->otp->begin($authorized, 'recover', $channel, ['target' => $target]);
        }
    }

    private function target(string $channel, string $target, ?int $userId = null): void
    {
        abort_unless(in_array($channel, ['email', 'sms'], true), 422);
        $rules = $channel === 'email' ? ['required', 'email', 'max:255'] : ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D'];
        // On recovery do not expose whether a proposed address belongs to another account.
        // Final uniqueness is checked only after successful possession proof.

        Validator::make(['target' => $target], ['target' => $rules])->validate();
    }
}
