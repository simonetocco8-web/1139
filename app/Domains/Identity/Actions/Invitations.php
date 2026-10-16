<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Identity\Delivery\DeliveryUnavailable;
use App\Domains\Identity\Models\Invitation;
use App\Domains\Identity\PasswordRules;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\MemberHistory;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Invitations
{
    public function __construct(private CodeTransport $delivery) {}

    /** @param list<string> $roles */
    public function lodge(int $lodgeId, string $email, array $roles, bool $bootstrap = false, ?int $personId = null): Invitation
    {
        $actor = VerifiedSession::recent();
        $email = mb_strtolower(trim($email));
        Validator::make(compact('email', 'roles'), ['email' => ['required', 'email', 'max:255'], 'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', 'distinct', Rule::in(array_keys(config('identity.roles')))]])->validate();
        if (! $bootstrap) {
            app(TenantContext::class)->assertTenant($lodgeId, 'administration');
        } else {
            abort_unless($actor->is_platform_admin && $roles === ['administrator'] && $email !== mb_strtolower(trim($actor->email)), 403);
        }
        $invitation = DB::transaction(function () use ($actor, $lodgeId, $email, $roles, $bootstrap, $personId): Invitation {
            $lodge = Lodge::query()->whereKey($lodgeId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if (! $bootstrap) {
                app(TenantContext::class)->assertTenant($lodgeId, 'administration');
            }
            if ($bootstrap) {
                abort_if(DB::table('lodge_user_roles')->where('lodge_id', $lodgeId)->where('role', 'administrator')->exists()
                    || Invitation::query()->where('lodge_id', $lodgeId)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->whereJsonContains('roles', 'administrator')->exists(), 403, 'Un amministratore è già stato predisposto.');
            }

            $recipient = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($recipient !== null) {
                app(SingleLodgeMembership::class)->assertAssignment($recipient->id, $lodgeId, $roles);
            }

            if ($personId !== null) {
                abort_if($bootstrap, 403);
                $person = Person::query()->where('lodge_id', $lodgeId)->whereKey($personId)->lockForUpdate()->firstOrFail();
                abort_unless($person->user_id === null && $person->email === $email && $person->enrollments()->where('status', 'active')->where('starts_on', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString()))->exists(), 409, 'Invito non disponibile per questa iscrizione.');
            }

            return $this->issue($email, $actor->id, $lodge->id, $roles, false, $bootstrap, $personId);
        }, 3);
        $this->ensureDelivered($invitation);

        return $invitation;
    }

    public function platform(string $email, bool $first = false): Invitation
    {
        $email = mb_strtolower(trim($email));
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();
        $actor = $first ? null : VerifiedSession::recent();
        abort_unless($first ? app()->runningInConsole() : $actor?->is_platform_admin, 403);
        $invitation = DB::transaction(function () use ($email, $actor, $first): Invitation {
            DB::table('identity_controls')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($first) {
                abort_if(User::query()->where('is_platform_admin', true)->exists()
                    || Invitation::query()->where('platform_admin', true)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->exists(), 403, 'Il primo amministratore è già predisposto.');
            }

            return $this->issue($email, $actor?->id, null, [], true, false);
        }, 3);
        $this->ensureDelivered($invitation);

        return $invitation;
    }

    public function find(string $token): Invitation
    {
        return Invitation::query()->where('token_digest', hash('sha256', $token))->whereNull('accepted_at')->whereNull('revoked_at')->where('delivery_state', 'accepted')->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->firstOrFail();
    }

    /** @param array<string,mixed> $input */
    public function accept(string $token, array $input): User
    {
        $initial = $this->find($token);

        return DB::transaction(function () use ($initial, $token, $input): User {
            if ($initial->lodge_id !== null) {
                Lodge::query()->whereKey($initial->lodge_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            } else {
                DB::table('identity_controls')->where('id', 1)->lockForUpdate()->firstOrFail();
            }
            $invite = Invitation::query()->where('token_digest', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_if($invite->accepted_at !== null || $invite->revoked_at !== null || CarbonImmutable::parse($invite->expires_at)->lessThanOrEqualTo(now('UTC')) || $invite->delivery_state !== 'accepted', 404);
            $this->issuerStillAuthorized($invite);
            $person = null;
            if ($invite->person_id !== null) {
                $person = Person::query()->where('lodge_id', $invite->lodge_id)->whereKey($invite->person_id)->lockForUpdate()->firstOrFail();
                abort_unless($person->user_id === null && $person->email === $invite->email && $person->enrollments()->where('status', 'active')->where('starts_on', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString()))->exists(), 409);
            }
            $user = User::query()->where('email', $invite->email)->lockForUpdate()->first();
            if ($user !== null) {
                $authenticated = VerifiedSession::recent();
                abort_unless($authenticated->id === $user->id, 403);
            } else {
                $data = Validator::make($input, ['name' => ['required', 'string', 'max:120'], 'password' => PasswordRules::strong()])->validate();
                $user = User::query()->forceCreate(['name' => $data['name'], 'email' => $invite->email, 'password' => Hash::make($data['password']), 'is_active' => true, 'activated_at' => now('UTC'), 'email_verified_at' => now('UTC')]);
            }
            if ($invite->lodge_id !== null) {
                app(SingleLodgeMembership::class)->assertAssignment($user->id, $invite->lodge_id, $invite->roles);
            }
            if ($person !== null) {
                abort_if(Person::query()->where('lodge_id', $invite->lodge_id)->where('user_id', $user->id)->where('id', '!=', $person->id)->exists(), 409, 'Account già associato nella loggia.');
                $person->forceFill(['user_id' => $user->id, 'version' => $person->version + 1])->save();
                MemberHistory::query()->create(['lodge_id' => $person->lodge_id, 'person_id' => $person->id, 'actor_id' => $user->id, 'event' => 'account.linked', 'snapshot' => ['user_id' => $user->id], 'occurred_at' => now('UTC')]);
                Audit::record('membership.account.linked', $user->id, $person->lodge_id, metadata: ['person_id' => $person->id]);
            }
            if ($invite->platform_admin) {
                $user->forceFill(['is_platform_admin' => true])->save();
                app(SessionSecurity::class)->revoke($user, 'platform_access.invited');
            } elseif ($invite->lodge_id !== null) {
                DB::table('lodge_user')->updateOrInsert(['lodge_id' => $invite->lodge_id, 'user_id' => $user->id], ['is_active' => true]);
                foreach ($invite->roles as $role) {
                    DB::table('lodge_user_roles')->insertOrIgnore(['lodge_id' => $invite->lodge_id, 'user_id' => $user->id, 'role' => $role]);
                }
            }
            $invite->accepted_at = now('UTC');
            $invite->save();
            Audit::record('invitation.accepted', $user->id, $invite->lodge_id, $user->id);

            return $user;
        }, 3);
    }

    public function revoke(int $id): void
    {
        $actor = VerifiedSession::recent();
        $selected = session('active_lodge_id');
        $local = is_int($selected) && app(TenantAccess::class)->allows($actor, $selected, 'administration');
        $eligible = Invitation::query()->whereKey($id)->where(function ($query) use ($actor, $selected, $local): void {
            $query->whereRaw('1 = 0');
            if ($local) {
                $query->orWhere('lodge_id', $selected);
            }
            if ($actor->is_platform_admin) {
                $query->orWhere('platform_admin', true)->orWhere(fn ($q) => $q->where('bootstrap', true)->where('invited_by', $actor->id));
            }
        });
        $initial = $eligible->firstOrFail();
        DB::transaction(function () use ($eligible, $initial, $actor): void {
            if ($initial->lodge_id !== null) {
                Lodge::query()->whereKey($initial->lodge_id)->lockForUpdate()->firstOrFail();
            }
            $invite = (clone $eligible)->lockForUpdate()->firstOrFail();
            if ($invite->lodge_id !== null && ! ($invite->bootstrap && $invite->invited_by === $actor->id && $actor->is_platform_admin)) {
                app(TenantContext::class)->assertTenant($invite->lodge_id, 'administration');
            }
            abort_if($invite->accepted_at !== null, 409);
            $invite->revoked_at = now('UTC');
            $invite->save();
            Audit::record('invitation.revoked', $actor->id, $invite->lodge_id, metadata: ['invitation_id' => $invite->id]);
        }, 3);
    }

    private function issuerStillAuthorized(Invitation $invite): void
    {
        if ($invite->invited_by === null) {
            abort_unless($invite->platform_admin && ! User::query()->where('is_platform_admin', true)->exists(), 403);

            return;
        }
        $issuer = User::query()->findOrFail($invite->invited_by);
        abort_unless($issuer->is_active, 403);
        if ($invite->platform_admin) {
            abort_unless($issuer->is_platform_admin, 403);

            return;
        }
        if ($invite->bootstrap) {
            abort_unless($issuer->is_platform_admin && $invite->email !== mb_strtolower(trim($issuer->email))
                && ! DB::table('lodge_user_roles')->where('lodge_id', $invite->lodge_id)->where('role', 'administrator')->exists(), 403);
        } else {
            abort_unless(app(TenantAccess::class)->ordinary($issuer, $invite->lodge_id, 'administration'), 403);
        }
    }

    /** @param list<string> $roles */
    private function issue(string $email, ?int $actor, ?int $lodge, array $roles, bool $platform, bool $bootstrap, ?int $personId = null): Invitation
    {
        Invitation::query()->where('email', $email)->where('lodge_id', $lodge)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
        $token = bin2hex(random_bytes(32));
        $invite = Invitation::query()->create(['email' => $email, 'invited_by' => $actor, 'lodge_id' => $lodge, 'roles' => $roles, 'platform_admin' => $platform, 'bootstrap' => $bootstrap, 'person_id' => $personId, 'token_digest' => hash('sha256', $token), 'expires_at' => now('UTC')->addHours(24)]);
        try {
            $this->delivery->send('email', $email, 'Attivazione accesso', 'Hai ricevuto un invito ad accedere al servizio. Per continuare apri: '.route('invitation.show', $token)."\nIl link scade dopo 24 ore. Se non atteso, ignora il messaggio.");
            $invite->delivery_state = 'accepted';
        } catch (\Throwable) {
            $invite->delivery_state = 'failed';
            $invite->revoked_at = now('UTC');
            Audit::record('invitation.delivery.failed', $actor, $lodge);
        }
        $invite->save();
        Audit::record('invitation.created', $actor, $lodge, metadata: ['invitation_id' => $invite->id]);

        return $invite;
    }

    private function ensureDelivered(Invitation $invite): void
    {
        if ($invite->delivery_state !== 'accepted') {
            throw new DeliveryUnavailable($this->delivery->available('email') ? 'Invio non confermato. L’invito è stato revocato per sicurezza.' : 'Il canale email non è configurato. L’invito non è stato inviato.');
        }
    }
}
