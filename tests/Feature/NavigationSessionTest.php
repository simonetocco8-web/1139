<?php

namespace Tests\Feature;

use App\Domains\Identity\Actions\LocalRoles;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Membership\Actions\Members;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NavigationSessionTest extends TestCase
{
    use RefreshDatabase;

    private Lodge $lodge;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Europe/Rome'));
        config(['session.lifetime' => 30]);
        $o = Obedience::query()->create(['code' => 'NAV', 'name' => 'Obbedienza Fittizia', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'NAV', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $this->lodge = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Fittizia Navigazione', 'is_active' => true]);
        $this->user = User::factory()->create();
        DB::table('lodge_user')->insert(['user_id' => $this->user->id, 'lodge_id' => $this->lodge->id, 'is_active' => true]);
        DB::table('lodge_user_roles')->insert(['user_id' => $this->user->id, 'lodge_id' => $this->lodge->id, 'role' => 'administrator']);
        $this->actingAs($this->user);
        session()->put(['auth.second_factor_user_id' => $this->user->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $this->user->session_version]);
    }

    public function test_activity_renews_idle_session_but_never_refreshes_the_otp_proof(): void
    {
        $otpAt = session('auth.second_factor_verified_at');
        $this->get('/dashboard')->assertOk()->assertHeader('X-Session-Expires-At', (string) (now('UTC')->getTimestamp() + 1800));
        $this->travel(20)->minutes();
        $this->get('/loggia/fratelli')->assertOk()->assertHeader('X-Session-Expires-At', (string) (now('UTC')->getTimestamp() + 1800));
        $this->travel(20)->minutes();
        $this->get('/dashboard')->assertOk();
        $this->assertSame($otpAt, session('auth.second_factor_verified_at'));
        $this->post('/account/recapito', ['channel' => 'email', 'target' => 'nuovo@example.invalid', 'password' => 'password'])->assertForbidden();
        $this->travel(31)->minutes();
        $this->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_expired_or_future_activity_cannot_be_revived_by_a_page_request(): void
    {
        session()->put('auth.last_activity_at', now('UTC')->addMinute()->getTimestamp());
        $this->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_lodge_navigation_order_submenu_icons_and_personal_section(): void
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();
        preg_match('~<nav id="app-navigation".*?</nav>~s', $html, $match);
        $text = html_entity_decode(strip_tags($match[0]));
        $offset = 0;
        foreach (['Sezione Loggia', 'Panoramica Loggia', 'Fratelli della Loggia', 'Tesoreria', 'Canoni Sovrastruttura', 'Capitazioni', 'Accessi della Loggia', 'Impostazioni della Loggia', 'Sicurezza Account', 'Dati Personali e Privacy (GDPR)', 'Notifiche', 'Report Gestionali', 'Report Accessi', 'Sezione Personale', 'La mia posizione'] as $label) {
            $position = strpos($text, $label, $offset);
            $this->assertNotFalse($position, $label);
            $offset = $position + strlen($label);
        }
        $this->assertSame(1, substr_count($text, 'La mia posizione'));
        $this->assertStringContainsString('lodge-settings-navigation', $match[0]);
        $this->assertStringContainsString('<svg', $match[0]);
        $this->assertStringNotContainsString('Il mio prospetto capitazioni', $text);
    }

    public function test_category_form_is_rendered_and_structure_label_is_explicit(): void
    {
        $this->get('/loggia/tesoreria/nuova')->assertOk()->assertSee('Nome categoria')->assertSee('name="name"', false)->assertDontSee("@include('treasury.input'", false)->assertSee('Movimento da/verso Sovrastruttura (opzionale)');
    }

    public function test_new_roles_are_persisted_and_remain_local(): void
    {
        foreach (['secretary' => 'Segretario', 'worshipful_master' => 'Maestro Venerabile'] as $role => $label) {
            DB::table('lodge_user_roles')->where('user_id', $this->user->id)->update(['role' => $role]);
            $this->assertDatabaseHas('lodge_user_roles', ['user_id' => $this->user->id, 'lodge_id' => $this->lodge->id, 'role' => $role]);
            $this->assertSame($label, __('identity.roles.'.$role));
            $this->assertArrayHasKey($role, config('identity.roles'));
            $this->get('/dashboard')->assertOk();
        }
    }

    public function test_secretary_manages_members_and_reports_without_financial_or_access_administration(): void
    {
        DB::table('lodge_user_roles')->where('user_id', $this->user->id)->update(['role' => 'secretary']);
        $access = app(TenantAccess::class);
        foreach (['read', 'members.manage', 'reports', 'export'] as $permission) {
            $this->assertTrue($access->allows($this->user, $this->lodge->id, $permission));
        }
        foreach (['create', 'update', 'reverse', 'administration', 'financial.read'] as $permission) {
            $this->assertFalse($access->allows($this->user, $this->lodge->id, $permission));
        }
        $this->get('/loggia/fratelli')->assertOk()->assertSee('Inserisci fratello')->assertDontSee('Impostazioni loggia');
        $this->get(route('members.create', $this->lodge->id))->assertOk();
        $this->get('/loggia/fratelli/importazione')->assertOk();
        $person = app(Members::class)->create($this->lodge->id, ['reference' => 'SECRETARY', 'first_name' => 'Persona', 'last_name' => 'Fittizia', 'starts_on' => '2026-01-01', 'status' => 'active', 'administrative_exemption' => false]);
        $this->get(route('members.show', [$this->lodge->id, $person->id]))->assertOk()->assertSee('Salva anagrafica')->assertDontSee('Invita all’accesso')->assertDontSee('Situazione debitoria capitazioni');

        $this->get('/loggia/fratelli/export')->assertOk();
        $this->get(route('reports.show', [$this->lodge->id, 'financial']))->assertOk();
        foreach (['/loggia/tesoreria', '/loggia/tesoreria/nuova', '/loggia/impostazioni', '/loggia/accessi'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/dashboard')->assertOk()->assertDontSee('Nuova operazione')->assertDontSee('Ultimi movimenti nel periodo');
    }

    public function test_master_has_local_administration_and_cannot_access_another_lodge(): void
    {
        DB::table('lodge_user_roles')->where('user_id', $this->user->id)->update(['role' => 'worshipful_master']);
        foreach (config('identity.permissions') as $permission) {
            $this->assertTrue(app(TenantAccess::class)->allows($this->user, $this->lodge->id, $permission));
            $this->assertFalse(app(TenantAccess::class)->allows($this->user, $this->lodge->id + 100, $permission));
        }
        $this->get('/loggia/impostazioni')->assertOk();
        $this->get('/loggia/accessi')->assertOk();
        $this->get('/loggia/tesoreria/nuova')->assertOk();
        try {
            app(LocalRoles::class)->update($this->user->id, ['brother'], true);
            $this->fail('Non deve essere rimosso l’ultimo amministratore.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('roles', $error->errors());
        }
        $this->get('/loggia/report-accessi')->assertOk()->assertSee('Maestro Venerabile');

        $this->get(route('members.create', $this->lodge->id + 100))->assertStatus(409);
    }
}
