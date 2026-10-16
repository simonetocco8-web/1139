<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Secret;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class OtpService
{
    public function __construct(private CodeTransport $delivery, private SessionSecurity $sessions) {}

    public function credentials(string $email, string $password): ?User
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        $valid = Hash::check($password, $user->password ?? (string) config('identity.dummy_password_hash'));
        if (! $valid || $user === null || ! $user->is_active || $user->activated_at === null || $user->email_verified_at === null) {
            Audit::record('login.credentials.rejected');

            return null;
        }

        return $user;
    }

    public function beginLogin(?User $user): OtpChallenge
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->begin($user, 'login', $user->otp_channel ?? 'email');
    }

    /** @param array<string, mixed> $payload */
    public function begin(?User $user, string $purpose, string $channel, array $payload = []): OtpChallenge
    {
        session()->put('otp.nonce', bin2hex(random_bytes(32)));
        session()->put('otp.started_at', now('UTC')->getTimestamp());
        $issued = DB::transaction(function () use ($user, $purpose, $channel, $payload): array {
            if ($user !== null) {
                $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                // Credentials may have been reset between checking the password and obtaining the lock.
                if ($fresh->session_version !== $user->session_version || $fresh->password !== $user->password || ! $fresh->is_active) {
                    $user = null;
                } else {
                    $user = $fresh;
                }
            }

            return $this->issue($user, $purpose, $channel, $payload);
        }, 3);
        $challenge = $this->deliver($issued);
        session()->put('otp.challenge_id', $challenge->id);

        return $challenge;
    }

    public function resend(): ?OtpChallenge
    {
        $original = $this->pending();
        if ($original === null) {
            return null;
        }
        $issued = DB::transaction(function () use ($original): ?array {
            $user = $original->user_id === null ? null : User::query()->whereKey($original->user_id)->lockForUpdate()->first();
            $old = OtpChallenge::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($old->consumed_at !== null || $old->invalidated_at !== null
                || CarbonImmutable::parse($old->last_sent_at)->addSeconds(60)->isFuture()
                || ($user !== null && $old->credential_version !== $user->session_version)) {
                return null;
            }
            $old->forceFill(['invalidated_at' => now('UTC')])->save();

            return $this->issue($user, $old->purpose, $old->channel, $old->payload ?? []);
        }, 3);
        $challenge = $issued === null ? null : $this->deliver($issued);
        if ($challenge !== null) {
            session()->put('otp.challenge_id', $challenge->id);
        }

        return $challenge;
    }

    public function verify(string $code): ?string
    {
        $original = $this->pending();
        if ($original === null) {
            return null;
        }
        $result = DB::transaction(function () use ($original, $code): ?array {
            $user = $original->user_id === null ? null : User::query()->whereKey($original->user_id)->lockForUpdate()->first();
            $challenge = OtpChallenge::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($challenge->consumed_at !== null || $challenge->invalidated_at !== null
                || $challenge->attempts >= 5 || CarbonImmutable::parse($challenge->expires_at)->lessThanOrEqualTo(now('UTC'))
                || $challenge->delivery_state !== 'accepted') {
                return null;
            }
            $attempts = $challenge->attempts + 1;
            $challenge->attempts = $attempts;
            $valid = preg_match('/^[0-9]{6}$/D', $code) === 1
                && hash_equals($challenge->code_digest, Secret::digest('otp', $challenge->id.'|'.$challenge->purpose.'|'.$code))
                && $user !== null && $user->is_active && $user->activated_at !== null
                && $challenge->credential_version === $user->session_version;
            if ($valid && $challenge->purpose === 'login') {
                $destination = $this->destination($user, $challenge->channel);
                $valid = $destination !== null && $challenge->channel === $user->otp_channel
                    && hash_equals($challenge->payload['destination_digest'] ?? '', Secret::digest('destination', $destination));
            }
            if (! $valid) {
                if ($attempts >= 5) {
                    $challenge->invalidated_at = now('UTC');
                }
                $challenge->save();
                Audit::record('otp.rejected', subject: $challenge->user_id);

                return null;
            }
            $challenge->consumed_at = now('UTC');
            $challenge->save();
            if ($challenge->purpose !== 'login') {
                $payload = $challenge->payload ?? [];
                $target = $payload['target'] ?? null;
                if (! is_string($target) || ! in_array($challenge->purpose, ['change_email', 'change_phone', 'recover'], true)) {
                    return null;
                }
                $field = $challenge->channel === 'email' ? 'email' : 'phone';
                if ($field === 'email' && User::query()->where('email', $target)->where('id', '!=', $user->id)->exists()) {
                    Audit::record('contact.change.rejected', $user->id);

                    return null;
                }
                if ($field === 'email') {
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                }
                $user->forceFill([$field => $target, $field.'_verified_at' => now('UTC'), 'otp_channel' => $challenge->channel])->save();
                $this->sessions->revoke($user, 'contact.changed');
            }
            Audit::record('otp.consumed', $user->id, subject: $user->id, metadata: ['purpose' => $challenge->purpose]);

            return ['user' => $user, 'purpose' => $challenge->purpose];
        }, 3);
        if ($result === null) {
            return null;
        }
        // The challenge is committed as consumed BEFORE creating the full authenticated session.
        if ($result['purpose'] === 'login') {
            session()->forget(['otp', 'active_lodge_id']);
            session()->regenerate(true);
            Auth::login($result['user'], false);
            session()->put('auth.second_factor_user_id', $result['user']->id);
            session()->put('auth.second_factor_verified_at', now('UTC')->getTimestamp());
            session()->put('auth.last_activity_at', now('UTC')->getTimestamp());
            session()->put('auth.session_version', $result['user']->session_version);
            try {
                Audit::record('login.completed', $result['user']->id);
            } catch (\Throwable) {
                Auth::logout();
                session()->invalidate();
                session()->regenerateToken();
                throw new \RuntimeException('Verifica non completata.');
            }
        } else {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        return $result['purpose'];
    }

    public function pending(): ?OtpChallenge
    {
        $id = session('otp.challenge_id');
        $nonce = session('otp.nonce');
        $started = session('otp.started_at');
        if (! is_string($id) || ! is_string($nonce) || ! is_int($started) || $started < now('UTC')->getTimestamp() - 900) {
            return null;
        }

        return OtpChallenge::query()->whereKey($id)->where('session_binding', Secret::digest('session', $nonce))->first();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{challenge:OtpChallenge,code:string,destination:mixed}
     */
    private function issue(?User $user, string $purpose, string $channel, array $payload): array
    {
        $destination = $payload['target'] ?? ($user === null ? null : $this->destination($user, $channel));
        if ($user !== null) {
            OtpChallenge::query()->where('user_id', $user->id)->where('purpose', $purpose)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now('UTC')]);
        }
        $id = (string) Str::uuid();
        $code = sprintf('%06d', random_int(0, 999999));
        if ($purpose === 'login' && is_string($destination)) {
            $payload['destination_digest'] = Secret::digest('destination', $destination);
        }
        $challenge = OtpChallenge::query()->create(['id' => $id, 'user_id' => $user?->id, 'purpose' => $purpose, 'channel' => $channel, 'session_binding' => Secret::digest('session', (string) session('otp.nonce')), 'code_digest' => Secret::digest('otp', $id.'|'.$purpose.'|'.$code), 'credential_version' => $user->session_version ?? 0, 'payload' => $payload, 'expires_at' => now('UTC')->addMinutes(5), 'last_sent_at' => now('UTC')]);

        return ['challenge' => $challenge, 'code' => $code, 'destination' => $destination];
    }

    /** @param array{challenge:OtpChallenge,code:string,destination:mixed} $issued */
    private function deliver(array $issued): OtpChallenge
    {
        $challenge = $issued['challenge'];
        if ($challenge->user_id !== null && is_string($issued['destination'])) {
            $accepted = false;
            try {
                $this->delivery->send($challenge->channel, $issued['destination'], 'Codice di verifica', 'Codice di verifica: '.$issued['code'].'. Valido per 5 minuti. Non condividerlo.');
                $accepted = true;
            } catch (\Throwable) {
            }
            DB::transaction(function () use ($challenge, $accepted): void {
                $fresh = OtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
                $fresh->delivery_state = $accepted ? 'accepted' : 'failed';
                $fresh->save();
                if (! $accepted) {
                    Audit::record('delivery.failed', subject: $fresh->user_id, metadata: ['channel' => $fresh->channel]);
                }
            }, 3);
        }

        return $challenge->fresh();
    }

    private function destination(User $user, string $channel): ?string
    {
        if ($channel === 'email' && $user->email_verified_at !== null) {
            return $user->email;
        }
        if ($channel === 'sms' && $user->phone_verified_at !== null && $user->phone !== null) {
            return $user->phone;
        }

        return null;
    }
}
