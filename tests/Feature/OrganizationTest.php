<?php

namespace Tests\Feature;

use App\Domains\Organization\Actions\ActiveLodge;
use App\Domains\Organization\Actions\OrganizationActions;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use App\Livewire\Organization\LodgeSelector;
use App\Livewire\Organization\Manager;
use App\Models\User;
use Database\Seeders\OrganizationDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationActions $actions;

    private Obedience $owner;

    private Rite $rite;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actions = app(OrganizationActions::class);
        $this->admin = User::factory()->create(['is_platform_admin' => true]);
        $this->verify($this->admin);
        $this->rite = $this->actions->save('rites', ['code' => 'TEST_R', 'name' => 'Rito Fittizio Test', 'is_active' => true]);
        $this->owner = $this->actions->save('obediences', ['code' => 'TEST_A', 'name' => 'Obbedienza Fittizia Test', 'is_active' => true, 'rite_ids' => [$this->rite->id]]);
    }

    private function verify(User $user): void
    {
        $this->actingAs($user);
        session()->put('auth.second_factor_user_id', $user->id);
        session()->put('auth.second_factor_verified_at', now('UTC')->getTimestamp());
        session()->put('auth.session_version', $user->session_version);
    }

    /** @param array<string, mixed> $extra */
    private function structure(string $level, ?Structure $parent = null, array $extra = []): Structure
    {
        return $this->actions->save('structures', array_replace(['obedience_id' => $this->owner->id, 'name' => 'Struttura Fittizia '.$level, 'level' => $level, 'parent_id' => $parent?->id, 'is_active' => true], $extra));
    }

    /** @param array<string, mixed> $extra */
    private function lodge(array $extra = []): Lodge
    {
        return $this->actions->save('lodges', array_replace(['obedience_id' => $this->owner->id, 'rite_id' => $this->rite->id, 'number' => '17', 'name' => 'Loggia Fittizia Test', 'is_active' => true, 'structure_ids' => []], $extra));
    }

    private function membership(User $user, Lodge $lodge, string $role = 'administrator'): void
    {
        $user->lodges()->attach($lodge->id, ['is_active' => true]);
        DB::table('lodge_user_roles')->insert(['user_id' => $user->id, 'lodge_id' => $lodge->id, 'role' => $role]);
    }

    /** @param callable(): mixed $operation */
    private function invalid(string $field, callable $operation): void
    {
        try {
            $operation();
            $this->fail('La modifica doveva essere rifiutata.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_hierarchy_accepts_skipped_and_absent_intermediate_levels(): void
    {
        $national = $this->structure('national');
        $province = $this->structure('provincial', $national);
        $rootRegion = $this->structure('regional');
        $this->assertSame($national->id, $province->parent->id);
        $this->assertNull($rootRegion->parent);
        $lodge = $this->lodge(['structure_ids' => [$national->id, $province->id]]);
        $this->assertCount(2, $lodge->currentAssignments);
        $this->assertSame($this->owner->id, $lodge->obedience->id);
        $this->assertSame($this->rite->id, $lodge->rite->id);
    }

    public function test_hierarchy_rejects_self_parent_cycles_and_inverted_levels(): void
    {
        $national = $this->structure('national');
        $region = $this->structure('regional', $national);
        $this->invalid('parent_id', fn () => $this->actions->save('structures', array_replace($national->toArray(), ['parent_id' => $national->id]), $national->id));
        $this->invalid('parent_id', fn () => $this->actions->save('structures', array_replace($national->toArray(), ['parent_id' => $region->id]), $national->id));
        $this->invalid('parent_id', fn () => $this->structure('regional', $region));
        $this->assertNull($national->fresh()->parent_id);
    }

    public function test_cross_obedience_parent_and_territory_are_rejected_atomically(): void
    {
        $other = Obedience::query()->create(['code' => 'OTHER', 'name' => 'Obbedienza Fittizia Estera', 'is_active' => true]);
        $foreign = Structure::query()->create(['obedience_id' => $other->id, 'level' => 'national', 'name' => 'Struttura Fittizia Estera', 'is_active' => true]);
        $this->invalid('parent_id', fn () => $this->structure('regional', $foreign));
        $this->invalid('structure_ids', fn () => $this->lodge(['structure_ids' => [$foreign->id]]));
        $this->assertDatabaseCount('lodges', 0);
    }

    public function test_lodge_number_is_unique_within_obedience_only(): void
    {
        $this->lodge();
        $this->invalid('number', fn () => $this->lodge());
        $other = $this->actions->save('obediences', ['code' => 'TEST_B', 'name' => 'Obbedienza Fittizia Beta', 'is_active' => true, 'rite_ids' => [$this->rite->id]]);
        $second = $this->lodge(['obedience_id' => $other->id]);
        $this->assertSame('17', $second->number);
        $this->assertDatabaseCount('lodges', 2);
    }

    public function test_incompatible_rite_and_removal_of_used_association_are_rejected(): void
    {
        $otherRite = $this->actions->save('rites', ['code' => 'TEST_X', 'name' => 'Rito Fittizio X', 'is_active' => true]);
        $this->invalid('rite_id', fn () => $this->lodge(['rite_id' => $otherRite->id]));
        $this->lodge();
        $this->invalid('rite_ids', fn () => $this->actions->save('obediences', array_replace($this->owner->toArray(), ['rite_ids' => []]), $this->owner->id));
        $this->assertCount(1, $this->owner->fresh()->rites);
    }

    public function test_territorial_change_closes_old_link_and_preserves_snapshot_and_ids(): void
    {
        $first = $this->structure('provincial');
        $second = $this->structure('provincial', null, ['name' => 'Struttura Fittizia Nuova']);
        $lodge = $this->lodge(['structure_ids' => [$first->id]]);
        $old = $lodge->currentAssignments()->first();
        $this->travel(1)->days();
        $this->verify($this->admin);
        $this->actions->save('lodges', array_replace($lodge->toArray(), ['structure_ids' => [$second->id]]), $lodge->id);
        $this->actions->save('structures', array_replace($first->toArray(), ['name' => 'Struttura Fittizia Rinominata']), $first->id);
        $this->actions->setActive('structures', $first->id, false);
        $this->assertNotNull($old->fresh()->ended_at);
        $this->assertSame($first->name, $old->fresh()->structure_name);
        $this->assertSame($second->id, $lodge->fresh()->currentAssignments()->first()->structure_id);
        $this->assertDatabaseCount('lodge_territorial_assignments', 2);
        $this->assertSame($old->id, $old->fresh()->id);
        $this->travelBack();
    }

    public function test_same_territory_update_is_idempotent_and_disactivation_does_not_destroy_history(): void
    {
        $structure = $this->structure('regional');
        $lodge = $this->lodge(['structure_ids' => [$structure->id]]);
        $this->actions->save('lodges', array_replace($lodge->toArray(), ['structure_ids' => [$structure->id]]), $lodge->id);
        $this->actions->setActive('lodges', $lodge->id, false);
        $this->assertDatabaseCount('lodge_territorial_assignments', 1);
        $this->assertFalse($lodge->fresh()->is_active);
    }

    public function test_territorial_chain_rejects_unrelated_branches_and_repeated_levels(): void
    {
        $national = $this->structure('national');
        $region = $this->structure('regional');
        $province = $this->structure('provincial', $national);
        $otherProvince = $this->structure('provincial', $national, ['name' => 'Altra Provincia Fittizia']);
        $this->invalid('structure_ids', fn () => $this->lodge(['structure_ids' => [$region->id, $province->id]]));
        $this->invalid('structure_ids', fn () => $this->lodge(['structure_ids' => [$province->id, $otherProvince->id]]));
    }

    public function test_owner_and_structure_level_cannot_be_rewritten(): void
    {
        $structure = $this->structure('regional');
        $other = Obedience::query()->create(['code' => 'NEW_OWNER', 'name' => 'Obbedienza Fittizia Nuova']);
        $this->invalid('level', fn () => $this->actions->save('structures', array_replace($structure->toArray(), ['level' => 'national']), $structure->id));
        $this->invalid('obedience_id', fn () => $this->actions->save('structures', array_replace($structure->toArray(), ['obedience_id' => $other->id]), $structure->id));
    }

    public function test_new_links_to_inactive_structures_and_obediences_are_rejected(): void
    {
        $structure = $this->structure('regional');
        $this->actions->setActive('structures', $structure->id, false);
        $this->invalid('structure_ids', fn () => $this->lodge(['structure_ids' => [$structure->id]]));
        $this->actions->setActive('obediences', $this->owner->id, false);
        $this->invalid('obedience_id', fn () => $this->lodge());
    }

    public function test_model_forbids_destructive_deletion(): void
    {
        $this->expectException(LogicException::class);
        $this->owner->delete();
    }

    public function test_database_restricts_deletion_of_structure_with_historical_assignment(): void
    {
        $structure = $this->structure('regional');
        $lodge = $this->lodge(['structure_ids' => [$structure->id]]);
        $this->actions->save('lodges', array_replace($lodge->toArray(), ['structure_ids' => []]), $lodge->id);
        $this->expectException(QueryException::class);
        DB::table('structures')->where('id', $structure->id)->delete();
    }

    public function test_database_rejects_cross_owner_parent_without_service(): void
    {
        $other = Obedience::query()->create(['code' => 'FK_OTHER', 'name' => 'Fittizia FK']);
        $parent = $this->structure('national');
        $this->expectException(QueryException::class);
        Structure::query()->create(['obedience_id' => $other->id, 'parent_id' => $parent->id, 'level' => 'regional', 'name' => 'Fittizia']);
    }

    public function test_database_rejects_cross_owner_assignment_without_service(): void
    {
        $lodge = $this->lodge();
        $other = Obedience::query()->create(['code' => 'FK_OWNER', 'name' => 'Fittizia FK']);
        $structure = Structure::query()->create(['obedience_id' => $other->id, 'level' => 'national', 'name' => 'Fittizia']);
        $this->expectException(QueryException::class);
        TerritorialAssignment::query()->create(['lodge_id' => $lodge->id, 'obedience_id' => $this->owner->id, 'structure_id' => $structure->id, 'level' => 'national', 'structure_name' => 'Fittizia', 'starts_at' => now('UTC')]);
    }

    public function test_database_rejects_two_open_assignments_for_same_level(): void
    {
        $structure = $this->structure('regional');
        $lodge = $this->lodge(['structure_ids' => [$structure->id]]);
        $this->expectException(QueryException::class);
        TerritorialAssignment::query()->create(['lodge_id' => $lodge->id, 'obedience_id' => $this->owner->id, 'structure_id' => $structure->id, 'level' => 'regional', 'structure_name' => 'Fittizia', 'starts_at' => now('UTC')]);
    }

    public function test_database_rejects_incompatible_rite_without_service(): void
    {
        $rite = Rite::query()->create(['code' => 'FK_RITE', 'name' => 'Rito Fittizio FK']);
        $this->expectException(QueryException::class);
        Lodge::query()->create(['obedience_id' => $this->owner->id, 'rite_id' => $rite->id, 'number' => '19', 'name' => 'Fittizia']);
    }

    public function test_platform_administration_does_not_imply_lodge_access(): void
    {
        $lodge = $this->lodge();
        $context = app(ActiveLodge::class);
        $this->assertCount(0, $context->authorized($this->admin)->get());
        Livewire::test(LodgeSelector::class)->call('select', $lodge->id)->assertNotFound();
        $this->assertNull(session('active_lodge_id'));
    }

    public function test_local_account_enters_one_authorized_lodge_without_a_selector(): void
    {
        $first = $this->lodge();
        $foreign = $this->lodge(['number' => '19', 'name' => 'Loggia Fittizia Non Autorizzata']);
        $user = User::factory()->create();
        $this->membership($user, $first);
        DB::table('lodge_user_roles')->insert(['lodge_id' => $first->id, 'user_id' => $user->id, 'role' => 'treasurer']);
        $this->verify($user);
        Livewire::test(LodgeSelector::class)->assertRedirect('/dashboard');
        $this->assertSame($first->id, session('active_lodge_id'));
        Livewire::test(LodgeSelector::class)->call('select', $foreign->id)->assertNotFound();
        $this->assertSame($first->id, session('active_lodge_id'));
    }

    public function test_revoked_membership_and_inactive_owner_clear_current_tenant(): void
    {
        $lodge = $this->lodge();
        $user = User::factory()->create();
        $this->membership($user, $lodge);
        $this->verify($user);
        $context = app(ActiveLodge::class);
        $context->select($lodge->id);
        DB::table('lodge_user')->where('user_id', $user->id)->update(['is_active' => false]);
        $this->assertNull($context->current());
        $this->assertNull(session('active_lodge_id'));
        DB::table('lodge_user')->where('user_id', $user->id)->update(['is_active' => true]);
        $context->select($lodge->id);
        $this->owner->update(['is_active' => false]);
        $this->assertNull($context->current());
    }

    public function test_ordinary_user_cannot_read_or_change_global_organization(): void
    {
        $user = User::factory()->create();
        $this->verify($user);
        $this->get('/admin/organizzazione/obediences')->assertForbidden();
        Livewire::test(Manager::class, ['kind' => 'obediences'])->assertForbidden();
    }

    public function test_guest_and_unverified_or_mismatched_second_factor_are_denied(): void
    {
        session()->forget('auth.second_factor_verified_at');
        $this->get('/admin/organizzazione/obediences')->assertForbidden();
        session()->put('auth.second_factor_verified_at', now('UTC')->getTimestamp());
        $this->verify($this->admin);
        session()->put('auth.second_factor_user_id', $this->admin->id + 1);
        $this->get('/admin/organizzazione/obediences')->assertForbidden();
        auth()->logout();
        $this->get('/admin/organizzazione/obediences')->assertUnauthorized();
    }

    public function test_ordinary_user_cannot_self_grant_platform_privilege_by_mass_assignment(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_platform_admin' => true])->save();
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_livewire_save_rechecks_privilege_after_initial_render(): void
    {
        $component = Livewire::test(Manager::class, ['kind' => 'rites'])->call('create')->set('form.code', 'FORBIDDEN')->set('form.name', 'Rito Fittizio Negato');
        $this->admin->is_platform_admin = false;
        $this->admin->save();
        $this->verify($this->admin->fresh());
        $component->call('save')->assertForbidden();
        $this->assertDatabaseMissing('rites', ['code' => 'FORBIDDEN']);
    }

    /** @return array<string,array{string}> */
    public static function screens(): array
    {
        return ['obbedienze' => ['obediences'], 'riti' => ['rites'], 'sovrastrutture' => ['structures'], 'logge' => ['lodges']];
    }

    #[DataProvider('screens')]
    public function test_admin_screens_render_and_form_opens(string $kind): void
    {
        $this->get('/admin/organizzazione/'.$kind)->assertOk()->assertSee('Aggiungi')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        Livewire::test(Manager::class, ['kind' => $kind])->call('create')->assertSee('Denominazione')->assertSee('Salva');
    }

    public function test_livewire_crud_search_filter_and_disactivation(): void
    {
        $component = Livewire::test(Manager::class, ['kind' => 'rites'])->call('create')->set('form.code', 'UI_RITE')->set('form.name', 'Rito Fittizio Interfaccia')->call('save')->assertHasNoErrors();
        $rite = Rite::query()->where('code', 'UI_RITE')->firstOrFail();
        $component->call('edit', $rite->id)->set('form.name', 'Rito Fittizio Modificato')->call('save')->assertHasNoErrors()
            ->set('search', 'UI_RITE')->assertSee('Rito Fittizio Modificato')->assertDontSee('Rito Fittizio Test')
            ->call('setActive', $rite->id, false)->set('status', 'active')->assertDontSee('Rito Fittizio Modificato')
            ->set('status', 'inactive')->assertSee('Rito Fittizio Modificato');
        $this->assertFalse($rite->fresh()->is_active);
        $this->assertDatabaseHas('organization_events', ['entity_type' => 'rites', 'entity_id' => $rite->id, 'event' => 'deactivated', 'actor_id' => $this->admin->id]);
    }

    public function test_demo_is_explicit_fictitious_idempotent_and_has_no_accounts(): void
    {
        $userCount = User::query()->count();
        $this->seed(OrganizationDemoSeeder::class);
        $count = Lodge::query()->count();
        $assignments = TerritorialAssignment::query()->count();
        $this->seed(OrganizationDemoSeeder::class);
        $this->assertSame($count, Lodge::query()->count());
        $this->assertSame($assignments, TerritorialAssignment::query()->count());
        $this->assertSame($userCount, User::query()->count());
        $this->assertDatabaseHas('lodges', ['name' => 'Loggia Fittizia Beta 001', 'number' => '17']);
    }

    public function test_reparenting_is_rejected_when_it_breaks_existing_lodge_territory(): void
    {
        $first = $this->structure('national');
        $second = $this->structure('national', null, ['name' => 'Nazionale Fittizia Alternativa']);
        $province = $this->structure('provincial', $first);
        $this->lodge(['structure_ids' => [$first->id, $province->id]]);
        $this->invalid('parent_id', fn () => $this->actions->save('structures', array_replace($province->toArray(), ['parent_id' => $second->id]), $province->id));
        $this->assertSame($first->id, $province->fresh()->parent_id);
    }

    public function test_closed_territorial_assignment_cannot_be_rewritten_or_reopened(): void
    {
        $structure = $this->structure('regional');
        $lodge = $this->lodge(['structure_ids' => [$structure->id]]);
        $old = $lodge->currentAssignments()->first();
        $this->actions->save('lodges', array_replace($lodge->toArray(), ['structure_ids' => []]), $lodge->id);
        $this->expectException(LogicException::class);
        $old->fresh()->update(['ended_at' => null]);
    }

    public function test_database_rejects_duplicate_lodge_number_without_service(): void
    {
        $this->lodge();
        $this->expectException(QueryException::class);
        Lodge::query()->create(['obedience_id' => $this->owner->id, 'rite_id' => $this->rite->id, 'number' => '17', 'name' => 'Loggia Fittizia Duplicata']);
    }

    public function test_demo_seed_is_forbidden_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(LogicException::class);
        (new OrganizationDemoSeeder)->run();
    }
}
