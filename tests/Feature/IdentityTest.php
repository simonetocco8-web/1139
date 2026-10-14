<?php

namespace Tests\Feature;

use App\Domains\Identity\Actions\AccountSecurity;
use App\Domains\Identity\Actions\Assistance;
use App\Domains\Identity\Actions\Invitations;
use App\Domains\Identity\Actions\LocalRoles;
use App\Domains\Identity\Actions\OtpService;
use App\Domains\Identity\Actions\SessionSecurity;
use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Identity\Delivery\DeliveryUnavailable;
use App\Domains\Identity\Delivery\FakeTransport;
use App\Domains\Identity\Models\Invitation;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Secret;
use App\Domains\Organization\Actions\ActiveLodge;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Livewire\Identity\AccessManager;
use App\Livewire\Identity\Security;
use App\Livewire\Organization\Manager;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $fake;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity.delivery_driver' => 'fake', 'identity.dummy_password_hash' => Hash::make('irrelevant-test-password')]);
        $this->fake = app(FakeTransport::class);
        $this->otp = app(OtpService::class);
    }

    private function verified(User $user): void
    {
        $this->actingAs($user);
        session()->put('auth.second_factor_user_id', $user->id);
        session()->put('auth.second_factor_verified_at', now('UTC')->getTimestamp());
        session()->put('auth.session_version', $user->session_version);
    }

    private function code(): string
    {
        $message = end($this->fake->messages);
        $this->assertIsArray($message);
        preg_match('/Codice di verifica: ([0-9]{6})\./', $message['body'], $matches);
        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }

    private function login(User $user): string
    {
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/otp');
        $this->assertGuest();

        return $this->code();
    }

    private function lodge(string $code): Lodge
    {
        $owner = Obedience::query()->create(['code' => $code, 'name' => 'Obbedienza Fittizia '.$code, 'is_active' => true]);
        $rite = Rite::query()->firstOrCreate(['code' => 'ID_RITE'], ['name' => 'Rito Fittizio', 'is_active' => true]);
        $owner->rites()->attach($rite->id);

        return Lodge::query()->create(['obedience_id' => $owner->id, 'rite_id' => $rite->id, 'name' => 'Loggia Fittizia '.$code, 'number' => '1', 'is_active' => true]);
    }

    private function role(User $user, Lodge $lodge, string $role): void
    {
        DB::table('lodge_user')->updateOrInsert(['user_id' => $user->id, 'lodge_id' => $lodge->id], ['is_active' => true]);
        DB::table('lodge_user_roles')->insertOrIgnore(['user_id' => $user->id, 'lodge_id' => $lodge->id, 'role' => $role]);
    }

    private function select(User $user, Lodge $lodge): void
    {
        $this->verified($user);
        app(ActiveLodge::class)->select($lodge->id);
    }

    private function token(): string
    {
        $message = end($this->fake->messages);
        preg_match('~/inviti/([a-f0-9]{64})~', $message['body'], $matches);
        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }

    public function test_fortify_credentials_create_only_pending_state_then_otp_creates_full_session(): void
    {
        $user = User::factory()->create();
        $code = $this->login($user);
        $this->assertNull(session('auth.second_factor_user_id'));
        $this->get('/dashboard')->assertUnauthorized();
        Livewire::test(Security::class)->assertUnauthorized();
        $this->post('/otp', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->id, session('auth.second_factor_user_id'));
        $this->assertSame($user->session_version, session('auth.session_version'));
        $this->assertNull(session('otp.challenge_id'));
        $this->get('/dashboard')->assertOk();
    }

    public function test_challenge_uses_domain_separated_digest_and_no_plaintext_code_column(): void
    {
        $code = $this->login(User::factory()->create());
        $challenge = $this->otp->pending();
        $this->assertSame(Secret::digest('otp', $challenge->id.'|login|'.$code), $challenge->code_digest);
        $this->assertNotSame($code, $challenge->code_digest);
        $this->assertFalse(Schema::hasColumn('otp_challenges', 'code'));
        $this->assertArrayNotHasKey('code', $challenge->payload);
        $this->assertNotSame($challenge->payload, $challenge->getRawOriginal('payload'));
    }

    public function test_wrong_credentials_are_uniform_for_known_and_unknown_email(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertRedirect('/otp');
        $first = session('status');
        $this->post('/login', ['email' => 'unknown@example.invalid', 'password' => 'wrong'])->assertRedirect('/otp');
        $this->assertSame($first, session('status'));
        $this->assertCount(0, $this->fake->messages);
        $this->assertGuest();
    }

    public function test_otp_is_invalid_at_exact_five_minute_boundary(): void
    {
        $code = $this->login(User::factory()->create());
        $this->travel(5)->minutes();
        $this->post('/otp', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->travelBack();
    }

    public function test_five_bad_attempts_close_challenge_and_cannot_be_reset_by_resend(): void
    {
        $good = $this->login(User::factory()->create());
        $bad = $good === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->post('/otp', ['code' => $bad])->assertSessionHasErrors('code');
        }
        $challenge = $this->otp->pending();
        $this->assertSame(5, $challenge->attempts);
        $this->assertNotNull($challenge->invalidated_at);
        $this->post('/otp', ['code' => $good])->assertSessionHasErrors('code');
        $this->travel(61)->seconds();
        $this->post('/otp/reinvio')->assertRedirect();
        $this->assertCount(1, $this->fake->messages);
        $this->assertGuest();
        $this->travelBack();
    }

    public function test_resend_waits_sixty_seconds_and_invalidates_old_challenge(): void
    {
        $this->login(User::factory()->create());
        $old = $this->otp->pending();
        $this->post('/otp/reinvio');
        $this->assertCount(1, $this->fake->messages);
        $this->travel(59)->seconds();
        $this->post('/otp/reinvio');
        $this->assertCount(1, $this->fake->messages);
        $this->travel(1)->seconds();
        $this->post('/otp/reinvio');
        $this->assertCount(2, $this->fake->messages);
        $this->assertNotSame($old->id, session('otp.challenge_id'));
        $this->assertNotNull($old->fresh()->invalidated_at);
        $newId = session('otp.challenge_id');
        $newCode = $this->code();
        session()->put('otp.challenge_id', $old->id);
        $this->assertNull($this->otp->verify($newCode));
        session()->put('otp.challenge_id', $newId);
        $this->post('/otp', ['code' => $newCode])->assertRedirect('/dashboard');
        $this->travelBack();
    }

    public function test_replay_and_nonce_tampering_cannot_authenticate(): void
    {
        $user = User::factory()->create();
        $code = $this->login($user);
        $oldSession = session('otp');
        session()->put('otp.nonce', 'wrong');
        $this->assertNull($this->otp->verify($code));
        session()->put('otp', $oldSession);
        $this->post('/otp', ['code' => $code])->assertRedirect('/dashboard');
        auth()->logout();
        session()->put('otp', $oldSession);
        $this->assertNull($this->otp->verify($code));
        $this->assertGuest();
    }

    public function test_credentials_reset_while_pending_prevents_old_otp_login(): void
    {
        $user = User::factory()->create();
        $code = $this->login($user);
        DB::transaction(fn () => app(SessionSecurity::class)->revoke($user));
        $this->post('/otp', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_missing_provider_and_delivery_failure_never_authenticate_or_claim_success(): void
    {
        config(['identity.delivery_driver' => 'configured', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.from.address' => '']);
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/otp');
        $this->assertStringContainsString('Nessun messaggio', session('status'));
        $this->assertSame('failed', $this->otp->pending()->delivery_state);
        $this->assertCount(0, $this->fake->messages);
        $this->assertGuest();
        config(['identity.delivery_driver' => 'fake']);
        $this->fake->fail = true;
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertSame('failed', $this->otp->pending()->delivery_state);
        $this->assertDatabaseHas('identity_events', ['event' => 'delivery.failed']);
    }

    public function test_fake_driver_is_not_available_outside_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(app(CodeTransport::class)->available('email'));
        $this->expectException(DeliveryUnavailable::class);
        app(CodeTransport::class)->send('sms', '+390000000000', 'Verifica', 'messaggio');
    }

    public function test_sms_provider_requires_https_configuration_and_explicit_acceptance(): void
    {
        config(['identity.delivery_driver' => 'configured', 'identity.sms.driver' => 'http', 'identity.sms.endpoint' => 'https://sms.example.invalid/send', 'identity.sms.token' => 'test-only-provider-token']);
        Http::fake(['*' => Http::sequence()->push(['accepted' => false], 200)->push(['accepted' => true], 202)]);
        try {
            app(CodeTransport::class)->send('sms', '+390000000000', 'Verifica', 'messaggio');
            $this->fail('Provider rifiutato.');
        } catch (DeliveryUnavailable) {
        }
        app(CodeTransport::class)->send('sms', '+390000000000', 'Verifica', 'messaggio');
        Http::assertSent(fn ($request) => $request['to'] === '+390000000000' && $request->hasHeader('Authorization', 'Bearer test-only-provider-token'));
        config(['identity.sms.endpoint' => 'http://sms.example.invalid/send']);
        $this->assertFalse(app(CodeTransport::class)->available('sms'));
    }

    public function test_sms_login_requires_a_verified_phone(): void
    {
        $user = User::factory()->create(['otp_channel' => 'sms', 'phone' => '+390000000000', 'phone_verified_at' => now('UTC')]);
        $code = $this->login($user);
        $this->assertSame('sms', end($this->fake->messages)['channel']);
        $this->assertNotSame($user->phone, $user->getRawOriginal('phone'));
        $this->post('/otp', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_phone_cannot_receive_or_pass_login(): void
    {
        $user = User::factory()->create(['otp_channel' => 'sms', 'phone' => '+390000000000', 'phone_verified_at' => null]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertCount(0, $this->fake->messages);
        $this->assertNull($this->otp->verify('123456'));
        $this->assertGuest();
    }

    public function test_login_limits_account_across_ips_and_ip_across_accounts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])->post('/login', ['email' => 'unknown@example.invalid', 'password' => 'wrong'])->assertRedirect('/otp');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->post('/login', ['email' => 'unknown@example.invalid', 'password' => 'wrong'])->assertStatus(429);
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200'])->post('/login', ['email' => 'missing'.$i.'@example.invalid', 'password' => 'wrong'])->assertRedirect('/otp');
        }
        $this->post('/login', ['email' => 'extra@example.invalid', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_password_recovery_is_uniform_and_reset_never_logs_in_or_replaces_factor(): void
    {
        $user = User::factory()->create(['otp_channel' => 'sms', 'phone' => '+390000000000', 'phone_verified_at' => now('UTC')]);
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        $status = session('status');
        $this->post('/forgot-password', ['email' => 'missing@example.invalid'])->assertRedirect();
        $this->assertSame($status, session('status'));
        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'NuovaPassword!2026', 'password_confirmation' => 'NuovaPassword!2026'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(Hash::check('NuovaPassword!2026', $user->fresh()->password));
        $this->assertSame('sms', $user->fresh()->otp_channel);
        $this->assertSame('+390000000000', $user->fresh()->phone);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'AltraPassword!2026', 'password_confirmation' => 'AltraPassword!2026'])->assertSessionHasErrors('email');
    }

    public function test_password_reset_errors_do_not_disclose_account_existence(): void
    {
        $user = User::factory()->create();
        $this->postJson('/reset-password', ['token' => 'bad', 'email' => $user->email, 'password' => 'NuovaPassword!2026'])->assertStatus(422)->assertExactJson(['message' => 'Richiesta non valida o scaduta.']);
        $this->postJson('/reset-password', ['token' => 'bad', 'email' => 'unknown@example.invalid', 'password' => 'NuovaPassword!2026'])->assertStatus(422)->assertExactJson(['message' => 'Richiesta non valida o scaduta.']);
    }

    public function test_contact_change_requires_fresh_factor_password_and_new_contact_otp(): void
    {
        $user = User::factory()->create();
        $this->verified($user);
        $this->post('/account/recapito', ['channel' => 'email', 'target' => 'new@example.invalid', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/account/recapito', ['channel' => 'email', 'target' => 'new@example.invalid', 'password' => 'password'])->assertRedirect('/otp');
        $this->assertSame($user->email, $user->fresh()->email);
        $code = $this->code();
        $this->assertSame('new@example.invalid', end($this->fake->messages)['destination']);
        $this->post('/otp', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame('new@example.invalid', $user->fresh()->email);
        $this->assertSame(1, $user->fresh()->session_version);
    }

    public function test_contact_changes_are_denied_for_old_or_unverified_sessions(): void
    {
        $user = User::factory()->create();
        $this->verified($user);
        session()->put('auth.second_factor_verified_at', now('UTC')->subMinutes(6)->getTimestamp());
        $this->post('/account/recapito', ['channel' => 'sms', 'target' => '+390000000000', 'password' => 'password'])->assertForbidden();
        $this->verified($user);
        session()->forget('auth.second_factor_user_id');
        $this->post('/account/recapito', ['channel' => 'sms', 'target' => '+390000000000', 'password' => 'password'])->assertForbidden();
        $this->assertCount(0, $this->fake->messages);
    }

    public function test_factor_recovery_requires_password_and_single_use_offline_code_and_new_otp(): void
    {
        $user = User::factory()->create();
        $this->verified($user);
        $codes = app(AccountSecurity::class)->recoveryCodes('password');
        $this->assertCount(10, $codes);
        $this->assertNotSame($codes[0], DB::table('identity_recovery_codes')->first()->digest);
        auth()->logout();
        $this->post('/recupero-secondo-fattore', ['email' => $user->email, 'password' => 'password', 'recovery_code' => $codes[0], 'channel' => 'sms', 'target' => '+390000000000'])->assertRedirect('/otp');
        $this->assertGuest();
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->post('/otp', ['code' => $this->code()])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame('sms', $user->fresh()->otp_channel);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->fake->messages = [];
        $this->post('/recupero-secondo-fattore', ['email' => $user->email, 'password' => 'password', 'recovery_code' => $codes[0], 'channel' => 'email', 'target' => 'other@example.invalid'])->assertRedirect('/otp');
        $this->assertCount(0, $this->fake->messages);
        $this->assertGuest();
    }

    public function test_email_only_or_invalid_recovery_proof_never_sends_new_factor(): void
    {
        $user = User::factory()->create();
        $this->post('/recupero-secondo-fattore', ['email' => $user->email, 'password' => 'password', 'recovery_code' => 'invalid', 'channel' => 'email', 'target' => 'other@example.invalid'])->assertRedirect('/otp');
        $this->assertGuest();
        $this->assertCount(0, $this->fake->messages);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_revoking_sessions_invalidates_a_previously_valid_session_even_without_database_sessions(): void
    {
        $user = User::factory()->create();
        $this->verified($user);
        $this->get('/dashboard')->assertOk();
        DB::transaction(fn () => app(SessionSecurity::class)->revoke($user));
        $this->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_invitation_activates_only_named_recipient_with_saved_roles_and_no_login(): void
    {
        $lodge = $this->lodge('INVITE');
        $admin = User::factory()->create();
        $this->role($admin, $lodge, 'administrator');
        $this->select($admin, $lodge);
        $invite = app(Invitations::class)->lodge($lodge->id, 'recipient@example.invalid', ['treasurer']);
        $token = $this->token();
        $this->assertNotSame($token, $invite->token_digest);
        auth()->logout();
        $this->post('/inviti/'.$token, ['name' => 'Utente Fittizio Invitato', 'password' => 'PasswordFittizia!2026', 'password_confirmation' => 'PasswordFittizia!2026', 'roles' => ['administrator'], 'is_platform_admin' => true, 'lodge_id' => 999])->assertRedirect('/login');
        $user = User::query()->where('email', 'recipient@example.invalid')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->activated_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_platform_admin);
        $this->assertGuest();
        $this->assertDatabaseHas('lodge_user_roles', ['lodge_id' => $lodge->id, 'user_id' => $user->id, 'role' => 'treasurer']);
        $this->assertDatabaseMissing('lodge_user_roles', ['lodge_id' => $lodge->id, 'user_id' => $user->id, 'role' => 'administrator']);
        $this->post('/inviti/'.$token, ['name' => 'Fittizio', 'password' => 'PasswordFittizia!2026', 'password_confirmation' => 'PasswordFittizia!2026'])->assertNotFound();
    }

    public function test_existing_account_invitation_cannot_reset_password_without_verified_same_account(): void
    {
        $lodge = $this->lodge('EXIST');
        $admin = User::factory()->create();
        $existing = User::factory()->create();
        $this->role($admin, $lodge, 'administrator');
        $this->select($admin, $lodge);
        app(Invitations::class)->lodge($lodge->id, $existing->email, ['brother']);
        $token = $this->token();
        auth()->logout();
        $this->post('/inviti/'.$token, ['name' => 'Intruso', 'password' => 'PasswordFittizia!2026', 'password_confirmation' => 'PasswordFittizia!2026'])->assertUnauthorized();
        $this->assertTrue(Hash::check('password', $existing->fresh()->password));
        $this->verified($existing);
        $this->post('/inviti/'.$token)->assertRedirect('/login');
        $this->assertDatabaseHas('lodge_user_roles', ['lodge_id' => $lodge->id, 'user_id' => $existing->id, 'role' => 'brother']);
    }

    public function test_revoked_inviter_and_expired_invitation_cannot_grant_roles(): void
    {
        $lodge = $this->lodge('REVOKED');
        $admin = User::factory()->create();
        $this->role($admin, $lodge, 'administrator');
        $this->select($admin, $lodge);
        $invite = app(Invitations::class)->lodge($lodge->id, 'recipient@example.invalid', ['brother']);
        $token = $this->token();
        DB::table('lodge_user')->where('user_id', $admin->id)->update(['is_active' => false]);
        auth()->logout();
        $this->post('/inviti/'.$token, ['name' => 'Fittizio', 'password' => 'PasswordFittizia!2026', 'password_confirmation' => 'PasswordFittizia!2026'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'recipient@example.invalid']);
        $invite->expires_at = now('UTC')->subSecond();
        $invite->save();
        $this->get('/inviti/'.$token)->assertNotFound();
    }

    public function test_first_superuser_cli_invite_has_no_default_password_and_cannot_repeat_or_be_self_bootstrap(): void
    {
        $this->artisan('identity:invite-superuser', ['email' => 'platform@example.invalid'])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $token = $this->token();
        $this->artisan('identity:invite-superuser', ['email' => 'second@example.invalid'])->assertFailed();
        $this->post('/inviti/'.$token, ['name' => 'Super User Fittizio', 'password' => 'PasswordFittizia!2026', 'password_confirmation' => 'PasswordFittizia!2026'])->assertRedirect('/login');
        $super = User::query()->firstOrFail();
        $this->assertTrue($super->is_platform_admin);
        $lodge = $this->lodge('BOOT');
        $this->verified($super);
        try {
            app(Invitations::class)->lodge($lodge->id, $super->email, ['administrator'], bootstrap: true);
            $this->fail('Bootstrap di sé stesso.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        app(Invitations::class)->lodge($lodge->id, 'localadmin@example.invalid', ['administrator'], bootstrap: true);
        $this->assertFalse(app(TenantAccess::class)->allows($super, $lodge->id, 'read'));
    }

    /** @return array<string,array{string,list<string>}> */
    public static function roleMatrix(): array
    {
        return ['admin' => ['administrator', ['read', 'create', 'update', 'reverse', 'export', 'administration', 'reports']], 'tesoriere' => ['treasurer', ['read', 'create', 'update', 'reverse', 'export', 'reports']], 'fratello' => ['brother', ['read']]];
    }

    #[DataProvider('roleMatrix')]
    public function test_permissions_are_granular_and_confined_to_assigned_lodge(string $role, array $expected): void
    {
        $a = $this->lodge('PERMA');
        $b = $this->lodge('PERMB');
        $user = User::factory()->create();
        $this->role($user, $a, $role);
        $access = app(TenantAccess::class);
        foreach (config('identity.permissions') as $permission) {
            $this->assertSame(in_array($permission, $expected, true), $access->allows($user, $a->id, $permission));
            $this->assertFalse($access->allows($user, $b->id, $permission));
        }
    }

    public function test_same_account_has_different_permissions_in_two_lodges_and_superuser_no_bypass(): void
    {
        $a = $this->lodge('ROLA');
        $b = $this->lodge('ROLB');
        $user = User::factory()->create(['is_platform_admin' => true]);
        $this->role($user, $a, 'administrator');
        $this->role($user, $b, 'brother');
        $access = app(TenantAccess::class);
        $this->assertTrue($access->allows($user, $a->id, 'administration'));
        $this->assertFalse($access->allows($user, $b->id, 'export'));
        $c = $this->lodge('ROLC');
        $this->assertFalse($access->allows($user, $c->id, 'read'));
    }

    public function test_fraternal_read_scopes_to_own_records_and_rejects_foreign_records(): void
    {
        $a = $this->lodge('OWNA');
        $b = $this->lodge('OWNB');
        $user = User::factory()->create();
        $this->role($user, $a, 'brother');
        $this->select($user, $a);
        $other = User::factory()->create();
        Audit::record('security.fixture', $user->id, $a->id);
        Audit::record('security.fixture', $other->id, $a->id);
        Audit::record('security.fixture', $user->id, $b->id);
        $records = app(TenantContext::class)->scope(SecurityFixtureRecord::query()->where('event', 'security.fixture'), 'read', 'actor_id')->get();
        $this->assertCount(1, $records);
        $this->assertSame($user->id, $records->first()->actor_id);
        $this->expectException(HttpException::class);
        app(TenantContext::class)->record(SecurityFixtureRecord::query()->where('lodge_id', $b->id)->first(), 'read', 'actor_id');
    }

    public function test_export_is_tenant_scoped_formula_safe_and_forbidden_to_treasurer_and_superuser_without_membership(): void
    {
        $a = $this->lodge('EXPA');
        $b = $this->lodge('EXPB');
        $user = User::factory()->create(['name' => '=FAKEFORMULA()']);
        $foreign = User::factory()->create(['email' => 'foreign@example.invalid']);
        $this->role($user, $a, 'administrator');
        $this->role($foreign, $b, 'administrator');
        $this->select($user, $a);
        $response = $this->get('/loggia/accessi/export')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=FAKEFORMULA()", $csv);
        $this->assertStringNotContainsString($foreign->email, $csv);
        $treasurer = User::factory()->create();
        $this->role($treasurer, $a, 'treasurer');
        $this->select($treasurer, $a);
        $this->get('/loggia/accessi/export')->assertForbidden();
        $super = User::factory()->create(['is_platform_admin' => true]);
        $this->verified($super);
        session()->put('active_lodge_id', $a->id);
        $this->get('/loggia/accessi/export')->assertStatus(409);
    }

    public function test_livewire_stale_tenant_and_revoked_permission_cannot_modify_roles(): void
    {
        $a = $this->lodge('STALEA');
        $b = $this->lodge('STALEB');
        $admin = User::factory()->create();
        $this->role($admin, $a, 'administrator');
        $this->role($admin, $b, 'administrator');
        $this->select($admin, $a);
        $component = Livewire::test(AccessManager::class)->call('edit', $admin->id);
        app(ActiveLodge::class)->select($b->id);
        $component->call('save')->assertStatus(409);
        $this->select($admin, $a);
        $component = Livewire::test(AccessManager::class);
        DB::table('lodge_user_roles')->where('lodge_id', $a->id)->where('user_id', $admin->id)->update(['role' => 'brother']);
        $component->call('invite')->assertForbidden();
    }

    public function test_last_administrator_cannot_remove_own_access_and_foreign_user_cannot_be_modified(): void
    {
        $a = $this->lodge('LASTA');
        $b = $this->lodge('LASTB');
        $admin = User::factory()->create();
        $foreign = User::factory()->create();
        $this->role($admin, $a, 'administrator');
        $this->role($foreign, $b, 'administrator');
        $this->select($admin, $a);
        try {
            app(LocalRoles::class)->update($admin->id, ['brother'], true);
            $this->fail('Ultimo amministratore.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roles', $e->errors());
        }
        Livewire::test(AccessManager::class)->call('edit', $foreign->id)->assertNotFound();
    }

    public function test_assistance_requires_explicit_different_local_approver_is_scoped_revocable_and_audited(): void
    {
        $lodge = $this->lodge('ASSIST');
        $super = User::factory()->create(['is_platform_admin' => true]);
        $admin = User::factory()->create();
        $this->role($admin, $lodge, 'administrator');
        $this->verified($super);
        $service = app(Assistance::class);
        $grant = $service->request($lodge->id, 'Verifica tecnica fittizia', ['read', 'reports'], 30);
        $this->assertFalse(app(TenantAccess::class)->allows($super, $lodge->id, 'read'));
        $this->select($admin, $lodge);
        $service->approve($grant->id);
        $this->verified($super);
        app(ActiveLodge::class)->select($lodge->id);
        $this->assertTrue(app(TenantAccess::class)->allows($super, $lodge->id, 'read'));
        $this->assertFalse(app(TenantAccess::class)->allows($super, $lodge->id, 'export'));
        $this->assertFalse(app(TenantAccess::class)->allows($super, $lodge->id, 'administration'));
        app(TenantContext::class)->resolve('reports');
        $this->assertDatabaseHas('identity_events', ['event' => 'assistance.used', 'lodge_id' => $lodge->id, 'actor_id' => $super->id]);
        $this->select($admin, $lodge);
        $service->revoke($grant->id);
        $this->assertFalse(app(TenantAccess::class)->allows($super, $lodge->id, 'read'));
    }

    public function test_assistance_expires_and_cannot_be_self_approved_or_request_identity_administration(): void
    {
        $lodge = $this->lodge('EXPIRE');
        $super = User::factory()->create(['is_platform_admin' => true]);
        $this->role($super, $lodge, 'administrator');
        $this->select($super, $lodge);
        $service = app(Assistance::class);
        $grant = $service->request($lodge->id, 'Verifica tecnica fittizia', ['read'], 1);
        try {
            $service->approve($grant->id);
            $this->fail('Auto approvazione.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            $service->request($lodge->id, 'Verifica tecnica fittizia', ['administration'], 1);
            $this->fail('Scope amministrazione.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('permissions.0', $e->errors());
        }
        DB::table('lodge_user')->where('user_id', $super->id)->update(['is_active' => false]);
        $admin = User::factory()->create();
        $this->role($admin, $lodge, 'administrator');
        $this->select($admin, $lodge);
        $service->approve($grant->id);
        $this->travel(1)->minutes();
        $this->assertFalse(app(TenantAccess::class)->allows($super, $lodge->id, 'read'));
        $this->travelBack();
    }

    public function test_technical_notifications_are_neutral_and_public_registration_and_legacy_bypasses_absent(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        foreach ($this->fake->messages as $message) {
            $this->assertStringNotContainsString('loggia', mb_strtolower($message['body']));
            $this->assertStringNotContainsString('obbedienza', mb_strtolower($message['subject']));
        }
        foreach (['/register', '/two-factor-challenge', '/passkeys/login', '/user/two-factor-authentication', '/user/password', '/api/login'] as $path) {
            $this->postJson($path)->assertNotFound();
        }
        $this->assertGuest();
    }

    public function test_disabled_account_never_receives_otp_or_uses_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/otp');
        $this->assertCount(0, $this->fake->messages);
        $this->verified($user);
        $this->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_client_supplied_authentication_markers_and_remember_me_do_not_bypass_otp(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true,
            'auth' => ['second_factor_user_id' => $user->id, 'second_factor_verified_at' => now('UTC')->getTimestamp(), 'session_version' => 0]])->assertRedirect('/otp');
        $this->assertGuest();
        $this->assertNull(session('auth.second_factor_user_id'));
        $this->get('/dashboard')->assertUnauthorized();
    }

    public function test_fifth_attempt_can_succeed_but_sixth_cannot_use_consumed_challenge(): void
    {
        $user = User::factory()->create();
        $code = $this->login($user);
        $challenge = $this->otp->pending();
        $bad = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 4; $i++) {
            $this->post('/otp', ['code' => $bad])->assertSessionHasErrors('code');
        }
        $this->post('/otp', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertSame(5, $challenge->fresh()->attempts);
        $this->assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_otp_verification_rate_limit_is_account_scoped_even_after_lockout(): void
    {
        $user = User::factory()->create();
        $good = $this->login($user);
        $bad = $good === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i + 1)])->post('/otp', ['code' => $bad])->assertSessionHasErrors('code');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.100'])->post('/otp', ['code' => $good])->assertStatus(429);
        $this->assertGuest();
        $this->assertSame(5, $this->otp->pending()->attempts);
    }

    public function test_resending_commits_invalidation_before_transport_and_does_not_hold_database_transaction(): void
    {
        $spy = new class($this->fake) implements CodeTransport
        {
            public array $levels = [];

            public ?string $previous = null;

            public bool $previousInvalidated = false;

            public function __construct(private FakeTransport $fake) {}

            public function available(string $channel): bool
            {
                return $this->fake->available($channel);
            }

            public function send(string $channel, string $destination, string $subject, string $body): void
            {
                $this->levels[] = DB::transactionLevel();
                if ($this->previous !== null) {
                    $this->previousInvalidated = OtpChallenge::query()->findOrFail($this->previous)->invalidated_at !== null;
                }
                $this->fake->send($channel, $destination, $subject, $body);
            }
        };
        $baseLevel = DB::transactionLevel();
        $service = new OtpService($spy, app(SessionSecurity::class));
        $user = User::factory()->create();
        $service->beginLogin($user);
        $spy->previous = session('otp.challenge_id');
        $this->travel(60)->seconds();
        $service->resend();
        $this->assertSame([$baseLevel, $baseLevel], $spy->levels);
        $this->assertTrue($spy->previousInvalidated);
        $this->travelBack();
    }

    public function test_proposed_email_does_not_enumerate_existing_accounts_before_possession_proof(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->verified($owner);
        $this->post('/account/recapito', ['channel' => 'email', 'target' => $other->email, 'password' => 'password'])->assertRedirect('/otp');
        $this->post('/otp', ['code' => $this->code()])->assertSessionHasErrors('code');
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertSame($other->email, $other->fresh()->email);
    }

    public function test_notifications_use_trusted_app_url_instead_of_untrusted_host_header(): void
    {
        $user = User::factory()->create();
        $this->withServerVariables(['HTTP_HOST' => 'attacker.example.invalid'])->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        $message = end($this->fake->messages);
        $this->assertStringContainsString(config('app.url'), $message['body']);
        $this->assertStringNotContainsString('attacker.example.invalid', $message['body']);
    }

    public function test_recovery_code_rotation_invalidates_previous_codes_and_only_full_session_can_generate(): void
    {
        $user = User::factory()->create();
        $this->verified($user);
        $security = app(AccountSecurity::class);
        $old = $security->recoveryCodes('password');
        $new = $security->recoveryCodes('password');
        $this->assertCount(10, $new);
        $this->assertNotSame($old, $new);
        $this->assertSame(10, DB::table('identity_recovery_codes')->whereNotNull('consumed_at')->count());
        auth()->logout();
        $this->post('/account/codici-recupero', ['password' => 'password'])->assertUnauthorized();
    }

    public function test_supplied_password_hash_is_hashed_as_input_and_cannot_install_weak_preimage(): void
    {
        $this->artisan('identity:invite-superuser', ['email' => 'hash@example.invalid'])->assertSuccessful();
        $token = $this->token();
        $input = Hash::make('weak');
        $this->post('/inviti/'.$token, ['name' => 'Utente Fittizio', 'password' => $input, 'password_confirmation' => $input])->assertRedirect('/login');
        $user = User::query()->firstOrFail();
        $this->assertFalse(Hash::check('weak', $user->password));
        $this->assertTrue(Hash::check($input, $user->password));
    }

    public function test_unconfigured_smtp_invitation_is_recorded_failed_revoked_and_never_activates_user(): void
    {
        config(['identity.delivery_driver' => 'configured', 'mail.mailers.smtp.host' => '', 'mail.from.address' => '']);
        $this->artisan('identity:invite-superuser', ['email' => 'absent@example.invalid'])->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('identity_invitations', ['delivery_state' => 'failed']);
        $this->assertNotNull(Invitation::query()->firstOrFail()->revoked_at);
        $this->assertCount(0, $this->fake->messages);
    }

    public function test_production_authentication_rejects_plain_http_and_fake_transport(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/login')->assertForbidden();
        $this->assertFalse(app(CodeTransport::class)->available('email'));
    }

    public function test_other_tenant_cannot_revoke_private_invitation_or_assistance(): void
    {
        $a = $this->lodge('IDORA');
        $b = $this->lodge('IDORB');
        $admin = User::factory()->create();
        $this->role($admin, $a, 'administrator');
        $this->select($admin, $a);
        $invite = app(Invitations::class)->lodge($a->id, 'idortarget@example.invalid', ['brother']);
        $super = User::factory()->create(['is_platform_admin' => true]);
        $this->verified($super);
        $grant = app(Assistance::class)->request($a->id, 'Verifica tecnica fittizia', ['read'], 10);
        $foreign = User::factory()->create();
        $this->role($foreign, $b, 'administrator');
        $this->select($foreign, $b);
        try {
            app(Invitations::class)->revoke($invite->id);
            $this->fail('Invito di altro tenant.');
        } catch (ModelNotFoundException) {
        }
        try {
            app(Assistance::class)->revoke($grant->id);
            $this->fail('Assistenza di altro tenant.');
        } catch (ModelNotFoundException) {
        }
        $this->assertNull($invite->fresh()->revoked_at);
        $this->assertNull($grant->fresh()->revoked_at);
    }

    public function test_context_rechecks_all_operation_permissions_on_server(): void
    {
        $lodge = $this->lodge('OPS');
        $user = User::factory()->create();
        $this->role($user, $lodge, 'brother');
        $this->select($user, $lodge);
        foreach (['create', 'update', 'reverse', 'export', 'administration', 'reports'] as $permission) {
            try {
                app(TenantContext::class)->resolve($permission);
                $this->fail('Operazione non autorizzata.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_pending_authenticated_marker_without_version_cannot_enter_livewire_or_export(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($user);
        session()->put('auth.second_factor_user_id', $user->id);
        session()->put('auth.second_factor_verified_at', now('UTC')->getTimestamp());
        Livewire::test(Manager::class, ['kind' => 'obediences'])->assertForbidden();
        $this->get('/loggia/accessi/export')->assertUnauthorized();
    }
}

class SecurityFixtureRecord extends Model
{
    protected $table = 'identity_events';

    protected $guarded = [];

    public $timestamps = false;
}
