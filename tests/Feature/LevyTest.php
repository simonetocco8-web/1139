<?php

namespace Tests\Feature;

use App\Domains\Levies\Actions\Balances;
use App\Domains\Levies\Actions\Generator;
use App\Domains\Levies\Actions\Payments;
use App\Domains\Levies\Actions\Rules;
use App\Domains\Levies\Models\Condition;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Levies\Models\Payment;
use App\Domains\Levies\Models\Program;
use App\Domains\Membership\Actions\Members;
use App\Domains\Membership\Models\Enrollment;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use App\Domains\Treasury\Adapters\TreasuryUnavailable;
use App\Domains\Treasury\Adapters\UnavailableDisbursementGateway;
use App\Domains\Treasury\Contracts\DisbursementGateway;
use App\Jobs\GenerateLevies;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LevyDemoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeDisbursementGateway;
use Tests\TestCase;

class LevyTest extends TestCase
{
    use RefreshDatabase;

    private Lodge $a;

    private Lodge $b;

    private Structure $national;

    private Structure $province;

    private User $admin;

    private Rules $rules;

    private Generator $generator;

    private FakeDisbursementGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00', 'Europe/Rome'));
        $o = Obedience::query()->create(['code' => 'LEV', 'name' => 'Obbedienza Fittizia Canoni', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'LEV', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $this->a = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Fittizia Alfa', 'is_active' => true]);
        $this->b = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '2', 'name' => 'Loggia Fittizia Beta', 'is_active' => true]);
        $this->national = Structure::query()->create(['obedience_id' => $o->id, 'level' => 'national', 'name' => 'Nazionale Fittizia', 'iban' => 'IT60X0542811101000000123456', 'is_active' => true]);
        $this->province = Structure::query()->create(['obedience_id' => $o->id, 'parent_id' => $this->national->id, 'level' => 'provincial', 'name' => 'Provincia Fittizia', 'is_active' => true]);
        foreach ([$this->a, $this->b] as $lodge) {
            foreach ([$this->national, $this->province] as $s) {
                $this->assign($lodge, $s, '2025-01-01 00:00:00');
            }
        }
        $this->admin = User::factory()->create(['is_platform_admin' => true]);
        $this->role($this->admin, $this->a, 'administrator');
        $this->role($this->admin, $this->b, 'administrator');
        $this->select($this->admin, $this->a);
        $this->rules = app(Rules::class);
        $this->generator = app(Generator::class);
        $this->gateway = new FakeDisbursementGateway;
        app()->instance(DisbursementGateway::class, $this->gateway);
    }

    private function assign(Lodge $l, Structure $s, string $start): TerritorialAssignment
    {
        return TerritorialAssignment::query()->create(['lodge_id' => $l->id, 'obedience_id' => $l->obedience_id, 'structure_id' => $s->id, 'level' => $s->level, 'structure_name' => $s->name, 'starts_at' => $start]);
    }

    private function role(User $u, Lodge $l, string $role): void
    {
        DB::table('lodge_user')->updateOrInsert(['lodge_id' => $l->id, 'user_id' => $u->id], ['is_active' => true]);
        DB::table('lodge_user_roles')->insertOrIgnore(['lodge_id' => $l->id, 'user_id' => $u->id, 'role' => $role]);
    }

    private function select(User $u, Lodge $l): void
    {
        $this->useLocalLodge($u, $l->id);
        $this->actingAs($u);
        session()->put(['active_lodge_id' => $l->id, 'auth.second_factor_user_id' => $u->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $u->session_version]);
    }

    /** @return array<string,mixed> */
    private function values(array $extra = []): array
    {
        return array_replace(['obedience_id' => $this->a->obedience_id, 'code' => 'CANONE', 'description' => 'Canone Fittizio', 'level' => 'national', 'periodicity' => 'monthly', 'starts_on' => '2026-01-01', 'ends_on' => null, 'basis' => 'fixed', 'amount' => '100.00', 'count_criterion' => 'active_at_start', 'due_basis' => 'start', 'due_days' => 10, 'is_active' => true, 'reason' => 'Condizione interamente fittizia', 'recipient_iban' => null], $extra);
    }

    private function program(array $extra = []): Program
    {
        return $this->rules->create($this->values($extra));
    }

    private function advance(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 12:00:00', 'Europe/Rome'));
        $this->select($this->admin, $this->a);
    }

    private function due(?Program $p = null, string $through = '2026-01-01'): Obligation
    {
        $p ??= $this->program();
        $this->generator->run($this->a->id, $through);

        return Obligation::query()->where('program_id', $p->id)->where('lodge_id', $this->a->id)->orderBy('period_start')->firstOrFail();
    }

    private function member(string $ref, string $start = '2025-01-01', ?string $end = null, string $status = 'active', bool $exempt = false): void
    {
        app(Members::class)->create((int) session('active_lodge_id'), ['reference' => $ref, 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio', 'starts_on' => $start, 'ends_on' => $end, 'status' => $status, 'administrative_exemption' => $exempt, 'exemption_reason' => $exempt ? 'Esenzione Fittizia' : null]);
    }

    private function pay(Obligation $o, string $amount, ?string $key = null): Payment
    {
        return app(Payments::class)->pay($this->a->id, $o->id, $amount, now()->toDateString(), 'cash-'.$this->a->id, $key ?? bin2hex(random_bytes(24)));
    }

    public static function periodicities(): array
    {
        return [['monthly', 12], ['quarterly', 4], ['semiannual', 2], ['annual', 1]];
    }

    #[DataProvider('periodicities')]
    public function test_all_periodicities_generate_once_without_financial_movements(string $frequency, int $count): void
    {
        $p = $this->program(['periodicity' => $frequency]);
        $this->advance('2026-12-31');
        $this->assertSame($count, $this->generator->run($this->a->id, '2026-12-31'));
        $this->assertSame(0, $this->generator->run($this->a->id, '2026-12-31'));
        (new GenerateLevies($this->a->id, '2026-12-31'))->handle($this->generator);
        $this->assertSame($count, Obligation::query()->where('program_id', $p->id)->count());
        $this->assertDatabaseCount('levy_payments', 0);
        $this->assertDatabaseMissing('identity_events', ['event' => 'test.treasury.disbursed']);
    }

    public function test_missing_intermediate_level_and_multiple_fee_families(): void
    {
        $this->program();
        $this->program(['code' => 'PROV', 'level' => 'provincial']);
        $this->program(['code' => 'REG', 'level' => 'regional']);
        $this->assertSame(2, $this->generator->run($this->a->id, '2026-01-01'));
        $this->assertEqualsCanonicalizing([$this->national->id, $this->province->id], Obligation::query()->pluck('recipient_id')->all());
    }

    public function test_count_criterion_reference_and_evidence_are_saved(): void
    {
        $this->member('ACTIVE');
        $this->member('EXEMPT', exempt: true);
        $this->member('SUSPENDED', status: 'suspended');
        $this->member('ENDED', '2025-01-01', '2026-01-01', 'ended');
        $this->member('OLD', '2025-01-01', '2025-12-31', 'ended');
        $this->member('FUTURE', '2026-01-02');
        $p = $this->program(['basis' => 'per_member', 'amount' => '12.34']);
        $due = $this->due($p);
        $this->assertSame(3, $due->member_count);
        $this->assertSame('37.02', $due->amount);
        $this->assertCount(3, $due->snapshot['members']);
        $this->assertSame('2026-01-01', $due->reference_on->toDateString());
        $this->assertSame('2025-12-31 23:00:00.000000', $due->snapshot['reference_instant_utc']);
        Enrollment::query()->where('lodge_id', $this->a->id)->update(['status' => 'suspended']);
        $this->assertSame(0, $this->generator->run($this->a->id, '2026-01-01'));
        $this->assertSame($due->snapshot, $due->fresh()->snapshot);
    }

    public function test_count_excludes_administrative_exemptions_only_when_configured(): void
    {
        $this->member('YES');
        $this->member('NO', exempt: true);
        $this->select($this->admin, $this->b);
        $this->member('FOREIGN');
        $this->select($this->admin, $this->a);
        $due = $this->due($this->program(['basis' => 'per_member', 'amount' => '19.99', 'count_criterion' => 'active_non_exempt_at_start']));
        $this->assertSame(1, $due->member_count);
        $this->assertSame('19.99', $due->amount);
    }

    public function test_empty_count_creates_zero_obligation_not_a_payment(): void
    {
        $due = $this->due($this->program(['basis' => 'per_member']));
        $this->assertSame('0.00', $due->amount);
        $this->assertSame('zero', Balances::of($due)['status']);
        $this->assertDatabaseCount('levy_payments', 0);
    }

    public function test_historical_assignment_survives_transfer_and_recipient_changes(): void
    {
        $p = $this->program();
        $jan = $this->due($p);
        $this->advance('2026-01-15');
        $old = TerritorialAssignment::query()->where('lodge_id', $this->a->id)->where('level', 'national')->firstOrFail();
        $old->update(['ended_at' => now('UTC')]);
        $new = Structure::query()->create(['obedience_id' => $this->a->obedience_id, 'name' => 'Nuova Nazionale Fittizia', 'level' => 'national', 'is_active' => true]);
        $this->assign($this->a, $new, now('UTC')->format('Y-m-d H:i:s.u'));
        $this->national->update(['name' => 'Nome variato Fittizio', 'iban' => null]);
        $this->advance('2026-02-01');
        $this->assertSame(1, $this->generator->run($this->a->id, '2026-02-01'));
        $feb = Obligation::query()->where('period_start', '2026-02-01')->firstOrFail();
        $this->assertSame($new->id, $feb->recipient_id);
        $this->assertSame('Nazionale Fittizia', $jan->fresh()->recipient_name);
        $this->assertSame('IT60X0542811101000000123456', $jan->fresh()->recipient_iban);
        $this->pay($jan, '25.00');
        $event = DB::table('identity_events')->where('event', 'test.treasury.disbursed')->first();
        $metadata = json_decode($event->metadata, true);
        $this->assertSame($this->national->id, $metadata['recipient_id']);
        $this->assertSame('Nazionale Fittizia', $metadata['recipient_name']);
    }

    public function test_historical_backfill_uses_assignment_at_reference_not_current(): void
    {
        $p = $this->program();
        $this->advance('2026-01-15');
        $old = TerritorialAssignment::query()->where('lodge_id', $this->a->id)->where('level', 'national')->firstOrFail();
        $old->update(['ended_at' => now('UTC')]);
        $new = Structure::query()->create(['obedience_id' => $this->a->obedience_id, 'name' => 'Altra Nazionale Fittizia', 'level' => 'national', 'is_active' => true]);
        $this->assign($this->a, $new, now('UTC')->format('Y-m-d H:i:s.u'));
        $this->advance('2026-02-01');
        $this->generator->run($this->a->id, '2026-02-01');
        $this->assertSame($this->national->id, Obligation::query()->where('period_start', '2026-01-01')->firstOrFail()->recipient_id);
    }

    public function test_scope_precedence_and_expired_override_falls_back_without_rewriting(): void
    {
        $p = $this->program();
        $jan = $this->due($p);
        $this->rules->general($p->id, $this->values(['effective_on' => '2026-02-01', 'amount' => '110.00']));
        $this->rules->general($p->id, $this->values(['effective_on' => '2026-02-01', 'structure_id' => $this->national->id, 'amount' => '120.00']));
        $this->rules->local($this->a->id, $p->id, $this->values(['effective_on' => '2026-02-01', 'ends_on' => '2026-02-28', 'amount' => '30.00']));
        $this->advance('2026-03-01');
        $this->generator->run($this->a->id, '2026-03-01');
        $this->assertSame(['100.00', '30.00', '120.00'], Obligation::query()->orderBy('period_start')->pluck('amount')->all());
        $this->assertSame($jan->snapshot, $jan->fresh()->snapshot);
        $this->generator->run($this->b->id, '2026-03-01');
        $this->assertSame('120.00', Obligation::query()->where('lodge_id', $this->b->id)->where('period_start', '2026-02-01')->firstOrFail()->amount);
    }

    public function test_inactive_override_suppresses_baseline_and_can_be_reenabled(): void
    {
        $p = $this->program();
        $this->rules->local($this->a->id, $p->id, $this->values(['effective_on' => '2026-02-01', 'is_active' => false]));
        $this->rules->local($this->a->id, $p->id, $this->values(['effective_on' => '2026-03-01']));
        $this->advance('2026-03-01');
        $this->assertSame(2, $this->generator->run($this->a->id, '2026-03-01'));
        $this->assertSame(['2026-01-01', '2026-03-01'], Obligation::query()->orderBy('period_start')->get()->map(fn ($o) => $o->period_start->toDateString())->all());
    }

    public function test_future_changes_require_cycle_boundary_and_reject_retroactive_rules(): void
    {
        $p = $this->program();
        foreach (['2026-01-01', '2026-02-02'] as $date) {
            try {
                $this->rules->general($p->id, $this->values(['effective_on' => $date]));
                $this->fail();
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('levy_conditions', 1);
    }

    public function test_end_date_and_due_end_basis(): void
    {
        $p = $this->program(['periodicity' => 'quarterly', 'ends_on' => '2026-06-30', 'due_basis' => 'end', 'due_days' => 15]);
        $this->advance('2026-12-31');
        $this->generator->run($this->a->id, '2026-12-31');
        $this->assertDatabaseCount('levy_obligations', 2);
        $this->assertSame('2026-04-15', $this->due($p, '2026-12-31')->due_on->toDateString());
    }

    public function test_month_end_anchor_does_not_drift(): void
    {
        $p = $this->program(['starts_on' => '2026-01-31']);
        $this->advance('2026-03-31');
        $this->generator->run($this->a->id, '2026-03-31');
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], Obligation::query()->orderBy('period_start')->get()->map(fn ($o) => $o->period_start->toDateString())->all());
    }

    public function test_partial_successive_payments_and_idempotency_post_exactly_once(): void
    {
        $due = $this->due();
        $key = bin2hex(random_bytes(24));
        $first = $this->pay($due, '40.00', $key);
        $again = $this->pay($due, '40.00', $key);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('partial', Balances::of($due->fresh())['status']);
        $this->assertSame(6000, Balances::of($due->fresh())['residual']);
        $this->pay($due, '60.00');
        $this->assertSame('paid', Balances::of($due->fresh())['status']);
        $this->assertSame(2, DB::table('identity_events')->where('event', 'test.treasury.disbursed')->count());
        $this->assertDatabaseCount('levy_payments', 2);
    }

    public function test_reusing_payment_key_for_other_payload_is_rejected(): void
    {
        $due = $this->due();
        $key = bin2hex(random_bytes(24));
        $this->pay($due, '10.00', $key);
        $this->expectException(HttpException::class);
        $this->pay($due, '20.00', $key);
    }

    public function test_overpayment_rejected_before_gateway(): void
    {
        $due = $this->due();
        try {
            $this->pay($due, '100.01');
            $this->fail();
        } catch (ValidationException) {
            $this->assertDatabaseCount('levy_payments', 0);
            $this->assertDatabaseMissing('identity_events', ['event' => 'test.treasury.disbursed']);
        }
    }

    public function test_gateway_failure_rolls_back_its_own_write_and_payment(): void
    {
        $due = $this->due();
        $this->gateway->failAfterWrite = true;
        try {
            $this->pay($due, '10.00');
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('levy_payments', 0);
            $this->assertDatabaseMissing('identity_events', ['event' => 'test.treasury.disbursed']);
        }
    }

    public function test_invalid_posting_rolls_back_everything(): void
    {
        $due = $this->due();
        $this->gateway->invalidReference = true;
        try {
            $this->pay($due, '10.00');
            $this->fail();
        } catch (\LogicException) {
            $this->assertDatabaseCount('levy_payments', 0);
            $this->assertDatabaseMissing('identity_events', ['event' => 'test.treasury.disbursed']);
        }
    }

    public function test_default_gateway_never_fakes_payment(): void
    {
        $due = $this->due();
        app()->instance(DisbursementGateway::class, new UnavailableDisbursementGateway);
        try {
            $this->pay($due, '20.00');
            $this->fail();
        } catch (TreasuryUnavailable) {
            $this->assertDatabaseCount('levy_payments', 0);
            $this->assertSame(10000, Balances::of($due)['residual']);
        }
    }

    public function test_foreign_account_rejected_before_posting(): void
    {
        $due = $this->due();
        $this->expectException(HttpException::class);
        app(Payments::class)->pay($this->a->id, $due->id, '10.00', '2026-01-01', 'cash-'.$this->b->id, bin2hex(random_bytes(24)));
    }

    public function test_tenant_and_role_guards_cover_urls_reports_actions_and_changed_selection(): void
    {
        $p = $this->program();
        $due = $this->due($p);
        $this->generator->run($this->b->id, '2026-01-01');
        $foreign = Obligation::query()->where('lodge_id', $this->b->id)->firstOrFail();
        $this->get(route('levies.detail', [$this->a->id, $foreign->id]))->assertNotFound();
        $this->get(route('levies.detail', [$this->b->id, $foreign->id]))->assertStatus(409);
        $u = User::factory()->create();
        $this->role($u, $this->a, 'brother');
        $this->select($u, $this->a);
        foreach (['levies.index', 'levies.conditions', 'levies.history'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('levies.detail', [$this->a->id, $due->id]))->assertForbidden();
        $this->get(route('levies.platform'))->assertForbidden();
        $this->post(route('levies.pay', [$this->a->id, $due->id]), ['confirmation' => '1', 'amount' => '10.00', 'paid_on' => '2026-01-01', 'account' => 'cash-'.$this->a->id, 'key' => bin2hex(random_bytes(24))])->assertForbidden();
        $this->assertDatabaseCount('levy_payments', 0);
    }

    public function test_global_super_user_does_not_gain_financial_access_or_local_overrides(): void
    {
        $p = $this->program();
        $this->rules->local($this->a->id, $p->id, $this->values(['effective_on' => '2026-02-01', 'reason' => 'SEGRETO_LOCALE_FITTIZIO']));
        $this->due($p);
        $u = User::factory()->create(['is_platform_admin' => true]);
        $this->select($u, $this->a);
        $this->get(route('levies.platform'))->assertOk()->assertDontSee('SEGRETO_LOCALE_FITTIZIO')->assertDontSee('Persone conteggiate');
        $this->get(route('levies.index'))->assertForbidden();
    }

    public function test_local_admin_cannot_define_global_rules_or_foreign_override(): void
    {
        $p = $this->program();
        $u = User::factory()->create();
        $this->role($u, $this->a, 'administrator');
        $this->select($u, $this->a);
        $this->post(route('levies.general', $p->id), $this->values(['confirmation' => 1, 'effective_on' => '2026-02-01']))->assertForbidden();
        $this->post(route('levies.local', [$this->b->id, $p->id]), $this->values(['confirmation' => 1, 'effective_on' => '2026-02-01']))->assertStatus(409);
    }

    public function test_private_pages_render_filters_and_totals(): void
    {
        $due = $this->due();
        $this->pay($due, '20.00');
        $this->get(route('levies.index'))->assertOk()->assertSee('80,00')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('levies.schedule', 'scadenze'))->assertOk()->assertSee('Scadenzario canoni');
        $this->get(route('levies.history'))->assertOk()->assertSee('20,00');
        $this->get(route('levies.conditions'))->assertOk();
        $this->get(route('levies.platform'))->assertOk();
        $this->get(route('levies.detail', [$this->a->id, $due->id]))->assertOk()->assertSee('Come è stato calcolato');
        $this->get(route('levies.index', ['state' => 'paid']))->assertOk()->assertDontSee('Dettaglio e pagamenti');
    }

    public function test_server_confirmation_and_otp_are_required(): void
    {
        $this->post(route('levies.create'), $this->values())->assertSessionHasErrors('confirmation');
        session()->forget('auth.second_factor_user_id');
        $this->get(route('levies.index'))->assertForbidden();
        $this->assertDatabaseCount('levy_programs', 0);
    }

    public function test_economic_records_are_immutable_and_dates_serialize_as_local_dates(): void
    {
        $due = $this->due();
        $this->assertSame('2026-01-01', $due->toArray()['reference_on']);
        foreach ([fn () => $due->update(['amount' => '1.00']), fn () => $due->delete(), fn () => Condition::query()->first()->update(['amount' => '1.00'])] as $f) {
            try {
                $f();
                $this->fail();
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_future_generation_is_rejected_and_inactive_lodge_is_blocked(): void
    {
        try {
            $this->generator->run($this->a->id, '2026-01-02');
            $this->fail();
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->a->update(['is_active' => false]);
        $this->expectException(HttpException::class);
        $this->generator->run($this->a->id, '2026-01-01');
    }

    public function test_database_rejects_payment_linked_to_another_tenant(): void
    {
        $due = $this->due();
        $this->expectException(QueryException::class);
        Payment::query()->create(['lodge_id' => $this->b->id, 'obligation_id' => $due->id, 'amount' => '10.00', 'paid_on' => '2026-01-01', 'account_reference' => 'wrong', 'account_label' => 'Fittizio', 'operation_reference' => 'test', 'idempotency_key' => str_repeat('a', 48), 'fingerprint' => str_repeat('b', 64), 'actor_id' => $this->admin->id, 'created_at' => now('UTC')]);
    }

    public function test_database_rejects_foreign_territorial_assignment(): void
    {
        $due = $this->due();
        $data = $due->getAttributes();
        unset($data['id']);
        $data['period_start'] = '2026-02-01';
        $data['assignment_id'] = TerritorialAssignment::query()->where('lodge_id', $this->b->id)->where('level', 'national')->firstOrFail()->id;
        $this->expectException(QueryException::class);
        DB::table('levy_obligations')->insert($data);
    }

    public function test_database_prevents_destructive_structure_delete(): void
    {
        $this->due();
        $this->expectException(QueryException::class);
        DB::table('structures')->where('id', $this->national->id)->delete();
    }

    public function test_different_obedience_program_cannot_be_used_for_local_override(): void
    {
        $o = Obedience::query()->create(['code' => 'FOREIGN', 'name' => 'Obbedienza Diversa Fittizia', 'is_active' => true]);
        $p = $this->program(['obedience_id' => $o->id]);
        $this->expectException(ModelNotFoundException::class);
        $this->rules->local($this->a->id, $p->id, $this->values(['effective_on' => '2026-02-01']));
    }

    public function test_global_scope_rejects_structure_from_another_level(): void
    {
        $p = $this->program();
        $this->expectException(ModelNotFoundException::class);
        $this->rules->general($p->id, $this->values(['effective_on' => '2026-02-01', 'structure_id' => $this->province->id]));
    }

    public function test_local_override_is_hidden_from_other_tenant(): void
    {
        $p = $this->program();
        $this->rules->local($this->a->id, $p->id, $this->values(['effective_on' => '2026-02-01', 'reason' => 'DEROGA_RISERVATA_ALFA']));
        $this->select($this->admin, $this->b);
        $this->get(route('levies.conditions'))->assertOk()->assertDontSee('DEROGA_RISERVATA_ALFA');
    }

    public function test_only_reports_permission_can_open_schedule_and_history(): void
    {
        $u = User::factory()->create();
        $this->role($u, $this->a, 'administrator');
        $this->select($u, $this->a);
        DB::table('lodge_user_roles')->where('user_id', $u->id)->delete();
        $this->get(route('levies.schedule', 'scadenze'))->assertForbidden();
        $this->get(route('levies.history'))->assertForbidden();
    }

    public function test_obligation_unique_key_excludes_recipient_to_prevent_duplicate_transfer_billing(): void
    {
        $due = $this->due();
        $data = $due->getAttributes();
        unset($data['id']);
        $this->expectException(QueryException::class);
        DB::table('levy_obligations')->insert($data);
    }

    public function test_demo_seeder_is_fictitious_idempotent_and_has_no_fake_payments(): void
    {
        $this->seed(LevyDemoSeeder::class);
        $count = Obligation::query()->count();
        $this->seed(LevyDemoSeeder::class);
        $this->assertSame($count, Obligation::query()->count());
        $this->assertGreaterThan(0, $count);
        $this->assertDatabaseCount('levy_payments', 0);
    }

    public function test_command_rejects_empty_limit_even_without_programs(): void
    {
        $this->expectException(ValidationException::class);
        $this->artisan('levy:generate', ['--through' => '']);
    }

    public function test_decimal_overflow_fails_atomically(): void
    {
        $this->member('ONE');
        $this->member('TWO');
        $p = $this->program(['basis' => 'per_member', 'amount' => '5000000000000.00']);
        try {
            $this->generator->run($this->a->id, '2026-01-01');
            $this->fail();
        } catch (\OverflowException) {
            $this->assertDatabaseCount('levy_obligations', 0);
        }
    }
}
