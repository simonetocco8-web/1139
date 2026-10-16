<?php

namespace Tests\Feature;

use App\Domains\Capitations\Actions\Balances;
use App\Domains\Capitations\Actions\Generator;
use App\Domains\Capitations\Actions\Payments;
use App\Domains\Capitations\Actions\Plans;
use App\Domains\Capitations\Calendar;
use App\Domains\Capitations\Models\Allocation;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;
use App\Domains\Membership\Actions\Members;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Treasury\Adapters\TreasuryUnavailable;
use App\Domains\Treasury\Adapters\UnavailableReceiptGateway;
use App\Domains\Treasury\Contracts\ReceiptGateway;
use App\Jobs\GenerateCapitations;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CapitationDemoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeReceiptGateway;
use Tests\TestCase;

class CapitationTest extends TestCase
{
    use RefreshDatabase;

    private Lodge $a;

    private Lodge $b;

    private User $admin;

    private Person $person;

    private Generator $generator;

    private Plans $plans;

    private FakeReceiptGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'Europe/Rome'));
        $o = Obedience::query()->create(['code' => 'CAP', 'name' => 'Obbedienza Fittizia', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'CAP', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $this->a = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'name' => 'Loggia Fittizia Alfa', 'number' => '1', 'is_active' => true]);
        $this->b = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'name' => 'Loggia Fittizia Beta', 'number' => '2', 'is_active' => true]);
        $this->admin = User::factory()->create();
        $this->role($this->admin, $this->a, 'administrator');
        $this->role($this->admin, $this->b, 'administrator');
        $this->select($this->admin, $this->a);
        $this->person = $this->person('LOCAL_1');
        $this->generator = app(Generator::class);
        $this->plans = app(Plans::class);
        $this->gateway = new FakeReceiptGateway;
        app()->instance(ReceiptGateway::class, $this->gateway);
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

    private function person(string $ref, string $start = '2026-01-01', ?string $end = null, string $status = 'active'): Person
    {
        return app(Members::class)->create((int) session('active_lodge_id'), ['reference' => $ref, 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio', 'email' => $ref.'@example.invalid', 'starts_on' => $start, 'ends_on' => $end, 'status' => $status, 'administrative_exemption' => false]);
    }

    /** @return array<string,mixed> */
    private function values(array $extra = []): array
    {
        return array_replace(['description' => 'Piano Fittizio', 'amount' => '100.00', 'periodicity' => 'monthly', 'starts_on' => '2026-01-01', 'ends_on' => null, 'due_basis' => 'start', 'due_days' => 0, 'prorata' => 'none', 'is_active' => true], $extra);
    }

    private function plan(array $extra = []): Plan
    {
        return $this->plans->create($this->a->id, $this->values($extra));
    }

    private function due(?Plan $plan = null, string $through = '2026-01-01'): Installment
    {
        $plan ??= $this->plan();
        $this->generator->run($this->a->id, $through);

        return Installment::query()->where('plan_id', $plan->id)->where('person_id', $this->person->id)->orderBy('period_start')->firstOrFail();
    }

    private function key(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function posts(): int
    {
        return DB::table('identity_events')->where('event', 'test.treasury.received')->count();
    }

    public static function frequencies(): array
    {
        return [['monthly', 10], ['quarterly', 4], ['semiannual', 2], ['annual', 1]];
    }

    #[DataProvider('frequencies')]
    public function test_periodic_generation_and_duplicate_job(string $frequency, int $count): void
    {
        $p = $this->plan(['periodicity' => $frequency]);
        $job = new GenerateCapitations($this->a->id, '2026-10-14');
        $job->handle($this->generator);
        $job->handle($this->generator);
        $this->assertSame($count, Installment::query()->where('plan_id', $p->id)->count());
        $this->assertSame(0, $this->posts());
        $this->assertDatabaseCount('capitation_receipts', 0);
    }

    public function test_month_end_anchor_does_not_drift_and_leap_year_is_supported(): void
    {
        $p = $this->plan(['starts_on' => '2024-01-31', 'ends_on' => '2024-04-30', 'due_basis' => 'end', 'due_days' => 5]);
        $this->assertSame('2024-02-29', Calendar::start($p, 1)->toDateString());
        $this->assertSame('2024-03-31', Calendar::start($p, 2)->toDateString());
        $this->generator->run($this->a->id, '2024-04-30');
        $this->assertDatabaseCount('capitation_installments', 0);
        $other = $this->plan(['starts_on' => '2026-01-31', 'ends_on' => '2026-03-31', 'due_basis' => 'end', 'due_days' => 5]);
        $this->generator->run($this->a->id, '2026-03-31');
        $first = Installment::query()->where('plan_id', $other->id)->orderBy('id')->first();
        $this->assertSame('2026-02-27', $first->period_end->toDateString());
        $this->assertSame('2026-03-04', $first->due_on->toDateString());
    }

    public function test_daily_prorata_for_join_and_cessation_with_half_up(): void
    {
        $this->person->enrollments()->first()->forceFill(['starts_on' => '2026-01-16', 'ends_on' => '2026-02-10', 'status' => 'ended'])->save();
        $p = $this->plan(['amount' => '31.00', 'prorata' => 'daily']);
        $this->generator->run($this->a->id, '2026-03-01');
        $rows = Installment::query()->where('plan_id', $p->id)->orderBy('period_start')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('16.00', $rows[0]->original_amount);
        $this->assertSame('11.07', $rows[1]->original_amount);
        $this->assertSame(10, $rows[1]->snapshot['active_days']);
    }

    public function test_none_prorata_charges_full_only_for_actual_membership(): void
    {
        $this->person->enrollments()->first()->forceFill(['starts_on' => '2026-01-31', 'ends_on' => '2026-01-31', 'status' => 'ended'])->save();
        $p = $this->plan();
        $this->generator->run($this->a->id, '2026-01-15');
        $this->assertDatabaseCount('capitation_installments', 0);
        $this->generator->run($this->a->id, '2026-02-01');
        $this->assertSame('100.00', Installment::query()->first()->original_amount);
        $this->assertDatabaseCount('capitation_installments', 1);
    }

    public function test_plan_end_mid_cycle_obeys_explicit_daily_prorata(): void
    {
        $due = $this->due($this->plan(['amount' => '31.00', 'prorata' => 'daily', 'ends_on' => '2026-01-10']));
        $this->assertSame('10.00', $due->original_amount);
        $this->generator->run($this->a->id, '2026-10-14');
        $this->assertDatabaseCount('capitation_installments', 1);
    }

    public function test_rejoining_in_same_cycle_does_not_duplicate_rate(): void
    {
        $first = $this->person->enrollments()->first();
        app(Members::class)->deactivate($this->a->id, $this->person->id, $first->id, 1, '2026-01-10');
        app(Members::class)->enrollment($this->a->id, $this->person->id, ['starts_on' => '2026-01-20', 'status' => 'active', 'administrative_exemption' => false]);
        $due = $this->due($this->plan(['amount' => '31.00', 'prorata' => 'daily']), '2026-01-31');
        $this->assertSame('22.00', $due->original_amount);
        $this->assertCount(2, $due->snapshot['enrollments']);
    }

    public function test_exemption_discount_and_custom_amount_apply_before_prorata(): void
    {
        $p = $this->plan();
        $exempt = $this->person('EXEMPT');
        $custom = $this->person('CUSTOM');
        foreach ([[$exempt, 'exempt', null, null], [$this->person, 'discount', null, '12.50'], [$custom, 'custom', '42.01', null]] as [$person,$kind,$amount,$percent]) {
            $this->plans->rule($this->a->id, $p->id, $person->id, ['effective_on' => '2026-01-01', 'kind' => $kind, 'amount' => $amount, 'discount_percent' => $percent, 'reason' => 'Condizione fittizia']);
        }
        $this->generator->run($this->a->id, '2026-01-01');
        $this->assertSame('0.00', Installment::query()->where('person_id', $exempt->id)->first()->original_amount);
        $this->assertSame('87.50', Installment::query()->where('person_id', $this->person->id)->first()->original_amount);
        $this->assertSame('42.01', Installment::query()->where('person_id', $custom->id)->first()->original_amount);
    }

    public function test_expired_person_rule_reverts_to_standard_without_resurrecting_old_rule(): void
    {
        $p = $this->plan();
        $this->plans->rule($this->a->id, $p->id, $this->person->id, ['effective_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'kind' => 'exempt', 'reason' => 'Esenzione fittizia']);
        $this->generator->run($this->a->id, '2026-02-01');
        $this->assertSame(['0.00', '100.00'], Installment::query()->orderBy('period_start')->pluck('original_amount')->all());
    }

    public function test_future_amount_changes_do_not_rewrite_matured_rates(): void
    {
        $p = $this->plan();
        $due = $this->due($p);
        $snapshot = $due->snapshot;
        $this->plans->revise($this->a->id, $p->id, $this->values(['amount' => '150.00', 'effective_on' => '2026-11-01']));
        $this->generator->run($this->a->id, '2026-10-14');
        $this->assertSame('100.00', $due->fresh()->original_amount);
        $this->assertSame($snapshot, $due->fresh()->snapshot);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'Europe/Rome'));
        $this->generator->run($this->a->id, '2026-11-01');
        $this->assertSame('150.00', Installment::query()->where('period_start', '2026-11-01')->first()->original_amount);
    }

    public function test_inactive_versions_preserve_previous_debt_and_skip_future_cycles(): void
    {
        $p = $this->plan();
        $this->due($p);
        $this->plans->revise($this->a->id, $p->id, $this->values(['effective_on' => '2026-11-01', 'is_active' => false]));
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00', 'Europe/Rome'));
        $this->generator->run($this->a->id, '2026-12-01');
        $this->assertDatabaseCount('capitation_installments', 10);
    }

    public function test_retroactive_revision_and_missing_prorata_are_rejected(): void
    {
        $p = $this->plan();
        $this->post(route('capitations.revise', [$this->a->id, $p->id]), $this->values(['effective_on' => '2026-01-01', 'confirmation' => 1]))->assertSessionHasErrors('effective_on');
        $v = $this->values(['confirmation' => 1]);
        unset($v['prorata']);
        $this->post(route('capitations.plan', $this->a->id), $v)->assertSessionHasErrors('prorata');
        $this->assertDatabaseCount('capitation_plans', 1);
    }

    public function test_partial_payment_and_multiple_installment_allocation(): void
    {
        $p = $this->plan();
        $this->generator->run($this->a->id, '2026-02-01');
        [$one,$two] = Installment::query()->orderBy('id')->get()->all();
        $receipt = app(Payments::class)->receive($this->a->id, $this->person->id, '150.00', '2026-02-01', 'cash-'.$this->a->id, $this->key(), [$one->id => '100.00', $two->id => '50.00']);
        $this->assertSame(0, Balances::credit($receipt));
        $this->assertSame(0, Balances::installment($one)['residual']);
        $this->assertSame(5000, Balances::installment($two)['residual']);
        $this->assertSame('overdue_partial', Balances::installment($two)['status']);
        $this->assertSame(1, $this->posts());
    }

    public function test_advance_credit_allocation_does_not_create_new_cash_entries(): void
    {
        $due = $this->due();
        $payments = app(Payments::class);
        $r = $payments->receive($this->a->id, $this->person->id, '250.00', '2026-01-01', 'cash-'.$this->a->id, $this->key());
        $this->assertSame(25000, Balances::credit($r));
        $payments->allocate($this->a->id, $this->person->id, $r->id, $this->key(), [$due->id => '40.00']);
        $this->assertSame(21000, Balances::credit($r));
        $this->assertSame(6000, Balances::installment($due)['residual']);
        $this->assertSame(1, $this->posts());
    }

    public function test_receipt_and_allocation_replays_are_idempotent_and_conflicts_rejected(): void
    {
        $due = $this->due();
        $p = app(Payments::class);
        $key = $this->key();
        $r = $p->receive($this->a->id, $this->person->id, '100.00', '2026-01-01', 'cash-'.$this->a->id, $key);
        $again = $p->receive($this->a->id, $this->person->id, '100.00', '2026-01-01', 'cash-'.$this->a->id, $key);
        $this->assertSame($r->id, $again->id);
        $k = $this->key();
        $p->allocate($this->a->id, $this->person->id, $r->id, $k, [$due->id => '40.00']);
        $p->allocate($this->a->id, $this->person->id, $r->id, $k, [$due->id => '40.00']);
        $this->assertDatabaseCount('capitation_allocations', 1);
        $this->assertSame(1, $this->posts());
        $this->expectException(HttpException::class);
        $p->allocate($this->a->id, $this->person->id, $r->id, $k, [$due->id => '50.00']);
    }

    public function test_reversal_releases_credit_and_corrections_preserve_original_amount(): void
    {
        $due = $this->due();
        $p = app(Payments::class);
        $r = $p->receive($this->a->id, $this->person->id, '100.00', '2026-01-01', 'cash-'.$this->a->id, $this->key(), [$due->id => '60.00']);
        $a = Allocation::query()->first();
        try {
            $p->correct($this->a->id, $due->id, '-50.00', 'Correzione fittizia', $this->key());
            $this->fail('Below allocated');
        } catch (ValidationException) {
            $this->assertSame(6000, Balances::installment($due)['paid']);
        }
        $p->reverse($this->a->id, $a->id, 'Annullamento fittizio');
        $p->reverse($this->a->id, $a->id, 'Annullamento fittizio');
        $this->assertSame(10000, Balances::credit($r));
        $key = $this->key();
        $p->correct($this->a->id, $due->id, '-50.00', 'Correzione fittizia', $key);
        $p->correct($this->a->id, $due->id, '-50.00', 'Correzione fittizia', $key);
        $this->assertSame('100.00', $due->fresh()->original_amount);
        $this->assertSame(5000, Balances::installment($due)['due']);
        $this->assertSame(1, $this->posts());
    }

    public function test_invalid_multi_allocation_rolls_back_and_never_posts(): void
    {
        $due = $this->due();
        $other = $this->person('OTHER');
        $this->generator->run($this->a->id, '2026-01-01');
        $foreign = Installment::query()->where('person_id', $other->id)->first();
        try {
            app(Payments::class)->receive($this->a->id, $this->person->id, '150.00', '2026-01-01', 'cash-'.$this->a->id, $this->key(), [$due->id => '100.00', $foreign->id => '50.00']);
            $this->fail('Wrong owner');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, $this->posts());
            $this->assertDatabaseCount('capitation_receipts', 0);
        }
    }

    public function test_insufficient_credit_and_overpayment_cannot_change_balances(): void
    {
        $due = $this->due();
        $p = app(Payments::class);
        $r = $p->receive($this->a->id, $this->person->id, '40.00', '2026-01-01', 'cash-'.$this->a->id, $this->key());
        foreach (['50.00', '101.00'] as $v) {
            try {
                $p->allocate($this->a->id, $this->person->id, $r->id, $this->key(), [$due->id => $v]);
                $this->fail('Invalid amount');
            } catch (ValidationException) {
                $this->assertSame(4000, Balances::credit($r));
                $this->assertDatabaseCount('capitation_allocations', 0);
            }
        }
    }

    public function test_unavailable_treasury_never_records_a_receipt(): void
    {
        $due = $this->due();
        app()->instance(ReceiptGateway::class, new UnavailableReceiptGateway);
        app()->forgetInstance(Payments::class);
        try {
            app(Payments::class)->receive($this->a->id, $this->person->id, '100.00', '2026-01-01', 'cash-'.$this->a->id, $this->key());
            $this->fail('Unavailable treasury');
        } catch (TreasuryUnavailable) {
            $this->assertDatabaseCount('capitation_receipts', 0);
            $this->assertSame(0, $this->posts());
            $this->assertSame(10000, Balances::installment($due)['residual']);
        }
        $this->get(route('capitations.person', [$this->a->id, $this->person->id]))->assertOk()->assertSee('Tesoreria non ancora disponibile');
    }

    public function test_gateway_failure_rolls_back_all_receipt_side_effects(): void
    {
        $this->due();
        $this->gateway->failAfterWrite = true;
        try {
            app(Payments::class)->receive($this->a->id, $this->person->id, '100.00', '2026-01-01', 'cash-'.$this->a->id, $this->key());
            $this->fail('Gateway failure');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('capitation_receipts', 0);
            $this->assertSame(0, $this->posts());
        }
    }

    public function test_brother_only_sees_own_position_per_selected_lodge(): void
    {
        $this->due();
        $other = $this->person('OTHER');
        $this->generator->run($this->a->id, '2026-01-01');
        $u = User::factory()->create();
        $this->person->forceFill(['user_id' => $u->id])->save();
        $this->role($u, $this->a, 'brother');
        $this->select($this->admin, $this->b);
        $b = $this->person('BETA_ONLY');
        $plan = $this->plans->create($this->b->id, $this->values(['description' => 'Piano Beta Riservato']));
        $this->generator->run($this->b->id, '2026-01-01');
        $b->forceFill(['user_id' => $u->id])->save();
        $this->select($u, $this->a);
        $this->get(route('capitations.personal'))->assertOk()->assertSee('LOCAL_1')->assertDontSee('OTHER')->assertDontSee('Piano Beta Riservato');
        $this->get(route('capitations.index'))->assertForbidden();
        $this->get(route('capitations.report', 'morosi'))->assertForbidden();
        $this->get(route('capitations.person', [$this->a->id, $other->id]))->assertForbidden();
        DB::table('lodge_user')->where('user_id', $u->id)->where('lodge_id', $this->a->id)->update(['is_active' => false]);
        $this->role($u, $this->b, 'brother');
        $this->select($u, $this->b);
        $this->get(route('capitations.personal'))->assertOk()->assertSee('BETA_ONLY')->assertDontSee('LOCAL_1');
    }

    public function test_cross_tenant_ids_and_super_user_without_grant_are_rejected(): void
    {
        $this->select($this->admin, $this->b);
        $person = $this->person('FOREIGN');
        $p = $this->plans->create($this->b->id, $this->values());
        $this->generator->run($this->b->id, '2026-01-01');
        $due = Installment::query()->where('lodge_id', $this->b->id)->first();
        $this->select($this->admin, $this->a);
        $this->get(route('capitations.person', [$this->a->id, $person->id]))->assertNotFound();
        $this->get(route('capitations.person', [$this->b->id, $person->id]))->assertStatus(409);
        $this->post(route('capitations.correct', [$this->a->id, $due->id]), ['amount' => '-1.00', 'reason' => 'Motivo fittizio', 'key' => $this->key(), 'confirmation' => 1])->assertNotFound();
        $super = User::factory()->create(['is_platform_admin' => true]);
        $this->select($super, $this->a);
        $this->get(route('capitations.index'))->assertStatus(409);
    }

    public function test_views_reports_and_confirmations_are_authorized_and_totals_are_exact(): void
    {
        $due = $this->due();
        $p = app(Payments::class);
        $p->receive($this->a->id, $this->person->id, '40.00', '2026-01-01', 'cash-'.$this->a->id, $this->key(), [$due->id => '40.00']);
        $this->get(route('capitations.index'))->assertOk()->assertSee('Piano Fittizio');
        foreach (['scadenze', 'morosi', 'riepilogo'] as $mode) {
            $this->get(route('capitations.report', $mode))->assertOk()->assertSee('60,00 €');
        }
        $this->get(route('capitations.person', [$this->a->id, $this->person->id]))->assertOk()->assertSee('Storico versamenti')->assertSee('40.00 EUR');
        $this->post(route('capitations.plan', $this->a->id), $this->values())->assertSessionHasErrors('confirmation');
    }

    public function test_immutable_records_and_large_exact_money_rounding(): void
    {
        $this->assertSame(10, Money::cents('0.10'));
        $this->assertSame(1, Money::ratio(1, 1, 2));
        $this->assertSame(999999999999999, Money::cents('9999999999999.99'));
        $this->assertSame(499999999999999, Money::ratio(999999999999998, 1, 2));
        $due = $this->due();
        $due->original_amount = '1.00';
        $this->expectException(\LogicException::class);
        $due->save();
    }

    public function test_money_rejects_floats_precision_negative_receipts_and_future_generation(): void
    {
        foreach (['1.001', '1e3', '-1.00', 'NaN'] as $v) {
            try {
                Money::cents($v);
                $this->fail('Bad money');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        $this->generator->run($this->a->id, '2027-01-01');
    }

    public static function partialFrequencies(): array
    {
        return [['quarterly', '300.00', '2026-03-15', '2026-03-31', '56.67'], ['semiannual', '600.00', '2026-06-01', '2026-06-30', '99.45'], ['annual', '1200.00', '2026-07-01', '2026-10-14', '604.93']];
    }

    #[DataProvider('partialFrequencies')]
    public function test_daily_prorata_for_long_cycles(string $frequency, string $amount, string $start, string $through, string $expected): void
    {
        $this->person->enrollments()->first()->forceFill(['starts_on' => $start])->save();
        $this->plan(['periodicity' => $frequency, 'amount' => $amount, 'prorata' => 'daily']);
        $this->generator->run($this->a->id, $through);
        $this->assertSame($expected, Installment::query()->first()->original_amount);
    }

    public function test_late_cessation_preserves_snapshot_and_requires_explicit_correction(): void
    {
        $due = $this->due($this->plan(['prorata' => 'daily']));
        $snapshot = $due->snapshot;
        $period = $this->person->enrollments()->first();
        app(Members::class)->deactivate($this->a->id, $this->person->id, $period->id, 1, '2026-01-15');
        $this->generator->run($this->a->id, '2026-02-01');
        $this->assertDatabaseCount('capitation_installments', 1);
        $this->assertSame('100.00', $due->fresh()->original_amount);
        $this->assertSame($snapshot, $due->fresh()->snapshot);
    }

    public function test_person_rules_cannot_change_already_started_conditions_retroactively(): void
    {
        $p = $this->plan();
        $this->due($p);
        $this->expectException(ValidationException::class);
        $this->plans->rule($this->a->id, $p->id, $this->person->id, ['effective_on' => '2026-01-01', 'kind' => 'exempt', 'reason' => 'Motivo fittizio']);
    }

    public function test_treasurer_can_operate_but_brother_cannot_post_or_correct(): void
    {
        $due = $this->due();
        $u = User::factory()->create();
        $this->role($u, $this->a, 'treasurer');
        $this->select($u, $this->a);
        $r = app(Payments::class)->receive($this->a->id, $this->person->id, '100.00', '2026-01-01', 'cash-'.$this->a->id, $this->key(), [$due->id => '30.00']);
        $this->assertSame(7000, Balances::credit($r));
        DB::table('lodge_user_roles')->where('user_id', $u->id)->update(['role' => 'brother']);
        $this->post(route('capitations.correct', [$this->a->id, $due->id]), ['amount' => '-1.00', 'reason' => 'Motivo fittizio', 'key' => $this->key(), 'confirmation' => 1])->assertForbidden();
        $this->post(route('capitations.receive', [$this->a->id, $this->person->id]), ['amount' => '100.00', 'received_on' => '2026-01-01', 'account' => 'cash-'.$this->a->id, 'key' => $this->key(), 'confirmation' => 1])->assertForbidden();
        $this->assertSame(1, $this->posts());
    }

    public function test_fake_gateway_and_gateway_failure_are_never_production_fallbacks(): void
    {
        $this->assertFalse((new UnavailableReceiptGateway)->available());
        app()->detectEnvironment(fn () => 'production');
        $this->assertFalse($this->gateway->available());
    }

    public function test_debt_report_aggregates_person_and_excludes_settled_and_zero_rates(): void
    {
        $p = $this->plan();
        $this->generator->run($this->a->id, '2026-02-01');
        $one = Installment::query()->orderBy('id')->first();
        $pay = app(Payments::class);
        $pay->receive($this->a->id, $this->person->id, '100.00', '2026-02-01', 'cash-'.$this->a->id, $this->key(), [$one->id => '100.00']);
        $this->get(route('capitations.report', 'morosi'))->assertOk()->assertSee('1 rate scadute')->assertSee('100,00 €');
    }

    public function test_demo_seed_is_idempotent_and_has_no_fake_cash_receipts(): void
    {
        $users = User::query()->count();
        $this->seed(CapitationDemoSeeder::class);
        $count = Installment::query()->count();
        $this->assertGreaterThan(0, $count);
        $this->seed(CapitationDemoSeeder::class);
        $this->assertSame($count, Installment::query()->count());
        $this->assertDatabaseCount('capitation_receipts', 0);
        $this->assertSame($users, User::query()->count());
    }

    public function test_http_receipt_and_credit_imputation_preserve_cash_count(): void
    {
        $due = $this->due();
        $key = $this->key();
        $this->post(route('capitations.receive', [$this->a->id, $this->person->id]), ['confirmation' => 1, 'amount' => '200.00', 'received_on' => '2026-01-01', 'account' => 'cash-'.$this->a->id, 'key' => $key, 'allocations' => [$due->id => '25.50']])->assertRedirect()->assertSessionHasNoErrors();
        $r = Receipt::query()->firstOrFail();
        $this->assertSame(17450, Balances::credit($r));
        $this->assertSame(7450, Balances::installment($due)['residual']);
        $this->post(route('capitations.allocate', [$this->a->id, $this->person->id, $r->id]), ['confirmation' => 1, 'key' => $this->key(), 'allocations' => [$due->id => '70.00']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(450, Balances::installment($due)['residual']);
        $this->assertSame(1, $this->posts());
    }

    public function test_receipt_replay_with_different_amount_is_rejected(): void
    {
        $this->due();
        $p = app(Payments::class);
        $key = $this->key();
        $p->receive($this->a->id, $this->person->id, '100.00', '2026-01-01', 'cash-'.$this->a->id, $key);
        try {
            $p->receive($this->a->id, $this->person->id, '101.00', '2026-01-01', 'cash-'.$this->a->id, $key);
            $this->fail('Conflicting receipt');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertDatabaseCount('capitation_receipts', 1);
            $this->assertSame(1, $this->posts());
        }
    }

    public function test_fractional_cents_are_not_lost_in_multiple_receipts(): void
    {
        $due = $this->due($this->plan(['amount' => '0.30']));
        $p = app(Payments::class);
        $p->receive($this->a->id, $this->person->id, '0.10', '2026-01-01', 'cash-'.$this->a->id, $this->key(), [$due->id => '0.10']);
        $p->receive($this->a->id, $this->person->id, '0.20', '2026-01-01', 'cash-'.$this->a->id, $this->key(), [$due->id => '0.20']);
        $this->assertSame(0, Balances::installment($due)['residual']);
        $this->assertSame('paid', Balances::installment($due)['status']);
        $this->assertSame(2, $this->posts());
    }
}
