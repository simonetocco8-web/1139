<?php

namespace Tests\Feature;

use App\Domains\Capitations\Actions\Generator as CapGenerator;
use App\Domains\Capitations\Actions\Payments as Capit;
use App\Domains\Capitations\Actions\Plans;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Identity\Audit;
use App\Domains\Levies\Actions\Generator as LevyGenerator;
use App\Domains\Levies\Actions\Payments as Levies;
use App\Domains\Levies\Actions\Rules;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Membership\Actions\Members;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use App\Domains\Reports\Builder as ReportBuilder;
use App\Domains\Reports\Dashboards;
use App\Domains\Reports\Document;
use App\Domains\Reports\Period;
use App\Domains\Reports\Runtime;
use App\Domains\Reports\Sources;
use App\Domains\Treasury\Actions\Balances;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Operation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManagementReportsTest extends TestCase
{
    use RefreshDatabase;

    private Lodge $a;

    private Lodge $b;

    private User $user;

    private Account $bank;

    private Account $cash;

    private Category $income;

    private Category $expense;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Europe/Rome'));
        $o = Obedience::query()->create(['code' => 'TRE', 'name' => 'Obbedienza Fittizia Tesoreria', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'TRE', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $this->a = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Fittizia Tesoreria Alfa', 'is_active' => true]);
        $this->b = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '2', 'name' => 'Loggia Fittizia Tesoreria Beta', 'is_active' => true]);
        $this->user = User::factory()->create();
        $this->role($this->user, $this->a);
        $this->role($this->user, $this->b);
        $this->select($this->user, $this->a);
        $this->ledger = app(Ledger::class);
        $this->bank = $this->account('Banca Fittizia', 'bank', '1000.00');
        $this->cash = $this->account('Cassa Fittizia', 'cash', '100.00');
        $this->income = $this->ledger->category($this->a->id, 'Entrata Fittizia', 'income');
        $this->expense = $this->ledger->category($this->a->id, 'Spesa Fittizia', 'expense');
    }

    public function test_italian_period_filters_match_iso_and_reject_invalid_calendar_dates(): void
    {
        $this->fixture();
        $iso = app(ReportBuilder::class)->make($this->a->id, 'financial', ['from' => '2026-10-01', 'to' => '2026-10-15']);
        $it = app(ReportBuilder::class)->make($this->a->id, 'financial', ['from' => '01/10/2026', 'to' => '15/10/2026']);
        $this->assertSame($iso->metrics, $it->metrics);
        $this->assertSame($iso->rows, $it->rows);
        $this->get(route('reports.show', [$this->a->id, 'financial', 'from' => '01/10/2026', 'to' => '15/10/2026']))->assertOk()->assertSee('01/10/2026')->assertSee('15/10/2026');
        $this->getJson(route('reports.show', [$this->a->id, 'financial', 'from' => '31/02/2026', 'to' => '15/10/2026']))->assertUnprocessable();
    }

    private function role(User $u, Lodge $l, string $role = 'administrator'): void
    {
        DB::table('lodge_user')->updateOrInsert(['lodge_id' => $l->id, 'user_id' => $u->id], ['is_active' => true]);
        DB::table('lodge_user_roles')->insertOrIgnore(['lodge_id' => $l->id, 'user_id' => $u->id, 'role' => $role]);
    }

    private function select(User $u, Lodge $l): void
    {
        $this->actingAs($u);
        session()->put(['active_lodge_id' => $l->id, 'auth.second_factor_user_id' => $u->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $u->session_version]);
    }

    private function key(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function account(string $name, string $type, string $amount, string $date = '2026-01-01', ?string $key = null): Account
    {
        return $this->ledger->account((int) session('active_lodge_id'), ['name' => $name, 'type' => $type, 'currency' => 'EUR', 'opening_amount' => $amount, 'opened_on' => $date], $key ?? $this->key());
    }

    private function financial(string $kind, string $amount, ?Account $account = null, array $extra = [], ?string $key = null): Operation
    {
        return $this->ledger->post($this->a->id, array_replace(['kind' => $kind, 'account_id' => ($account ?? $this->bank)->id, 'amount' => $amount, 'booked_on' => '2026-10-15', 'description' => 'Operazione interamente fittizia', 'category_id' => $kind === 'income' ? $this->income->id : ($kind === 'expense' ? $this->expense->id : null)], $extra), $key ?? $this->key());
    }

    private function person(): Person
    {
        return app(Members::class)->create($this->a->id, ['reference' => 'TRE_PERSON', 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio', 'starts_on' => '2026-01-01', 'status' => 'active', 'administrative_exemption' => false]);
    }

    private function installment(Person $person): Installment
    {
        $p = app(Plans::class)->create($this->a->id, ['description' => 'Piano Fittizio', 'amount' => '100.00', 'periodicity' => 'monthly', 'starts_on' => '2026-10-01', 'due_basis' => 'start', 'due_days' => 0, 'prorata' => 'none', 'is_active' => true]);
        app(CapGenerator::class)->run($this->a->id, '2026-10-15');

        return Installment::query()->where('plan_id', $p->id)->where('person_id', $person->id)->firstOrFail();
    }

    private function levy(): Obligation
    {
        $s = Structure::query()->create(['obedience_id' => $this->a->obedience_id, 'name' => 'Nazionale Fittizia Tesoreria', 'level' => 'national', 'is_active' => true]);
        TerritorialAssignment::query()->create(['lodge_id' => $this->a->id, 'obedience_id' => $this->a->obedience_id, 'structure_id' => $s->id, 'level' => 'national', 'structure_name' => $s->name, 'starts_at' => '2025-01-01 00:00:00']);
        $this->user->forceFill(['is_platform_admin' => true])->save();
        $this->select($this->user, $this->a);
        app(Rules::class)->create(['obedience_id' => $this->a->obedience_id, 'code' => 'TRE', 'description' => 'Canone Fittizio', 'level' => 'national', 'periodicity' => 'monthly', 'starts_on' => '2026-10-15', 'basis' => 'fixed', 'amount' => '100.00', 'count_criterion' => 'active_at_start', 'due_basis' => 'start', 'due_days' => 0, 'is_active' => true, 'reason' => 'Condizione fittizia']);
        app(LevyGenerator::class)->run($this->a->id, '2026-10-15');

        return Obligation::query()->firstOrFail();
    }

    private function row(array $extra = []): array
    {
        return array_replace(['booked_on' => '2026-10-15', 'amount' => '25.00', 'description' => 'Movimento bancario Fittizio', 'reference' => 'BANK_TEST_001'], $extra);
    }

    private function fixture(): array
    {
        $this->financial('income', '200.00', extra: ['booked_on' => '2026-10-03']);
        $this->financial('expense', '30.00', $this->cash, extra: ['booked_on' => '2026-10-04']);
        $this->financial('transfer', '50.00', extra: ['target_id' => $this->cash->id, 'booked_on' => '2026-10-05']);
        $this->financial('adjustment', '-10.00', extra: ['booked_on' => '2026-10-06']);
        $person = $this->person();
        $installment = $this->installment($person);
        $receipt = app(Capit::class)->receive($this->a->id, $person->id, '60.00', '2026-10-10', (string) $this->bank->id, $this->key(), [$installment->id => '40.00']);
        $due = $this->levy();
        $payment = app(Levies::class)->pay($this->a->id, $due->id, '25.00', '2026-10-15', (string) $this->bank->id, $this->key());
        $this->user->forceFill(['is_platform_admin' => false])->save();
        $this->select($this->user, $this->a);

        return compact('person', 'installment', 'receipt', 'due', 'payment');
    }

    private function report(string $kind, array $extra = []): Document
    {
        return app(ReportBuilder::class)->make($this->a->id, $kind, array_replace(['from' => '2026-10-01', 'to' => '2026-10-15'], $extra));
    }

    public function test_financial_equation_matches_real_balances_and_excludes_transfers(): void
    {
        $this->fixture();
        $d = $this->report('financial');
        $this->assertSame(110000, $d->metrics['Saldo iniziale periodo']);
        $this->assertSame(26000, $d->metrics['Entrate nette']);
        $this->assertSame(5500, $d->metrics['Uscite nette']);
        $this->assertSame(-1000, $d->metrics['Aperture e rettifiche']);
        $this->assertSame(19500, $d->metrics['Variazione finanziaria']);
        $this->assertSame(129500, $d->metrics['Saldo finale periodo']);
        $this->assertSame(129500, array_sum(array_column($d->rows, 'closing')));
        $this->assertSame(129500, Balances::account($this->a->id, $this->bank->id) + Balances::account($this->a->id, $this->cash->id));
    }

    public function test_bank_cash_and_category_reports_reconcile(): void
    {
        $this->fixture();
        $balances = $this->report('balances');
        $this->assertSame([117500, 12000], array_column($balances->rows, 'closing'));
        $categories = $this->report('categories');
        $this->assertSame(26000, array_sum(array_column($categories->rows, 'income')));
        $this->assertSame(5500, array_sum(array_column($categories->rows, 'expense')));
        $this->assertSame(20500, array_sum(array_column($categories->rows, 'net')));
    }

    public function test_unpaid_obligations_do_not_change_financial_report(): void
    {
        $p = $this->person();
        $this->installment($p);
        $this->levy();
        $d = $this->report('financial');
        $this->assertSame(110000, $d->metrics['Saldo finale periodo']);
        $this->assertSame(0, $d->metrics['Entrate nette']);
        $this->assertSame(0, $d->metrics['Uscite nette']);
        $this->assertSame(10000, $this->report('capitations')->metrics['Residuo']);
        $this->assertSame(10000, $this->report('levies')->metrics['Residuo']);
    }

    public function test_finance_uses_accounting_date_while_obligations_show_current_status(): void
    {
        $this->fixture();
        $financial = $this->report('financial', ['to' => '2026-10-09']);
        $debt = $this->report('capitations', ['to' => '2026-10-09']);
        $this->assertSame(126000, $financial->metrics['Saldo finale periodo']);
        $this->assertSame(20000, $financial->metrics['Entrate nette']);
        $this->assertSame(4000, $debt->metrics['Imputato / versato']);
        $this->assertStringContainsString('aggiornata al 15/10/2026', $debt->note);
    }

    public function test_later_credit_allocation_is_not_another_financial_income(): void
    {
        $f = $this->fixture();
        $before = $this->report('financial');
        app(Capit::class)->allocate($this->a->id, $f['person']->id, $f['receipt']->id, $this->key(), [$f['installment']->id => '20.00']);
        $after = $this->report('financial');
        $cap = $this->report('capitations');
        $this->assertSame($before->metrics, $after->metrics);
        $this->assertSame(6000, $cap->metrics['Imputato / versato']);
        $this->assertSame(4000, $cap->metrics['Residuo']);
    }

    public function test_reversals_update_financial_and_debt_reports_without_erasing_history(): void
    {
        $f = $this->fixture();
        foreach ([$f['receipt']->operation_reference, $f['payment']->operation_reference] as $reference) {
            $op = Operation::query()->where('public_id', $reference)->firstOrFail();
            $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Storno Fittizio per coerenza report', $this->key());
        }
        $finance = $this->report('financial');
        $this->assertSame(126000, $finance->metrics['Saldo finale periodo']);
        $this->assertSame(20000, $finance->metrics['Entrate nette']);
        $this->assertSame(3000, $finance->metrics['Uscite nette']);
        $this->assertSame(10000, $this->report('capitations')->metrics['Residuo']);
        $this->assertSame(10000, $this->report('levies')->metrics['Residuo']);
        $this->assertDatabaseCount('capitation_receipts', 1);
        $this->assertDatabaseCount('levy_payments', 1);
    }

    public function test_annual_rows_sum_to_year_totals_and_include_opening_as_equity(): void
    {
        $this->fixture();
        $d = app(ReportBuilder::class)->make($this->a->id, 'annual', ['year' => '2026']);
        $this->assertCount(10, $d->rows);
        $this->assertSame('2026-10-15', $d->period->to);
        $this->assertSame(0, $d->metrics['Saldo iniziale periodo']);
        $this->assertSame(109000, $d->metrics['Aperture e rettifiche']);
        $this->assertSame(129500, $d->metrics['Saldo finale periodo']);
        $this->assertSame($d->metrics['Entrate nette'], array_sum(array_column($d->rows, 'income')));
        $this->assertSame($d->metrics['Uscite nette'], array_sum(array_column($d->rows, 'expense')));
        $this->assertSame(110000, $d->rows[0]['closing']);
        $this->assertSame(129500, $d->rows[9]['closing']);
    }

    public function test_arrears_are_residual_not_gross_due_and_fully_paid_rates_disappear(): void
    {
        $f = $this->fixture();
        $d = $this->report('arrears');
        $this->assertSame(6000, $d->metrics['Residuo']);
        $this->assertCount(1, $d->rows);
        app(Capit::class)->receive($this->a->id, $f['person']->id, '60.00', '2026-10-15', (string) $this->bank->id, $this->key(), [$f['installment']->id => '60.00']);
        $this->assertSame(0, $this->report('arrears')->metrics['Residuo']);
        $this->assertSame([], $this->report('arrears')->rows);
    }

    public function test_empty_reports_render_and_have_zero_money_totals(): void
    {
        $d = $this->report('capitations');
        $this->assertSame([], $d->rows);
        $this->assertSame(0, $d->metrics['Dovuto']);
        $this->get(route('reports.show', [$this->a->id, 'capitations']))->assertOk()->assertSee('Nessun dato per il periodo selezionato.');
    }

    public function test_admin_dashboard_matches_report_balances_and_current_residuals(): void
    {
        $this->fixture();
        $d = app(Dashboards::class)->staff($this->a->id, new Period('2026-10-01', '2026-10-15'));
        $this->assertSame(117500, $d['bank']);
        $this->assertSame(12000, $d['cash']);
        $this->assertSame(6000, $d['rates']['residual']);
        $this->assertSame(7500, $d['levies']['residual']);
        $this->assertSame(129500, $d['flow']['closing']);
        $this->get('/dashboard')->assertOk()->assertSee('Panoramica della loggia')->assertSee('1295,00');
    }

    public function test_brother_dashboard_and_reports_are_owned_and_tenant_scoped(): void
    {
        $f = $this->fixture();
        $u = User::factory()->create();
        $f['person']->forceFill(['user_id' => $u->id])->save();
        $this->role($u, $this->a, 'brother');
        $this->select($u, $this->a);
        $d = app(Dashboards::class)->brother($this->a->id, new Period('2026-10-01', '2026-10-15'));
        $this->assertSame(10000, $d['totals']['due']);
        $this->assertSame(4000, $d['totals']['paid']);
        $this->assertSame(6000, $d['arrears']);
        $this->assertSame(2000, $d['credit']);
        $this->assertFalse($d['shared']);
        $this->get('/dashboard')->assertOk()->assertSee('La mia panoramica')->assertDontSee('Banca Fittizia')->assertDontSee('Nazionale Fittizia Tesoreria');
        $personal = $this->report('personal');
        $this->assertCount(1, $personal->rows);
        $this->assertSame(6000, $personal->metrics['Residuo']);
        $this->get(route('reports.show', [$this->a->id, 'financial']))->assertForbidden();
        $this->get(route('reports.show', [$this->a->id, 'capitations']))->assertForbidden();
        $this->get(route('reports.export', [$this->a->id, 'personal', 'csv']))->assertForbidden();
    }

    public function test_brother_without_linked_person_gets_no_other_people_finances(): void
    {
        $this->fixture();
        $u = User::factory()->create();
        $this->role($u, $this->a, 'brother');
        $this->select($u, $this->a);
        $this->get('/dashboard')->assertOk()->assertSee('Non è ancora presente un’anagrafica')->assertDontSee('NomeFittizio');
        $this->assertSame([], $this->report('personal')->rows);
    }

    public function test_brother_export_if_explicitly_granted_stays_personal(): void
    {
        $f = $this->fixture();
        $u = User::factory()->create();
        $f['person']->forceFill(['user_id' => $u->id])->save();
        $this->role($u, $this->a, 'brother');
        $this->select($u, $this->a);
        config(['identity.roles.brother' => ['read', 'export']]);
        $this->get(route('reports.export', [$this->a->id, 'personal', 'csv']))->assertOk()->assertSee('100,00', false)->assertDontSee('Banca Fittizia');
        $this->get(route('reports.export', [$this->a->id, 'financial', 'csv']))->assertForbidden();
    }

    public function test_shared_balances_never_include_account_names_or_transactions(): void
    {
        $f = $this->fixture();
        $u = User::factory()->create();
        $f['person']->forceFill(['user_id' => $u->id])->save();
        $this->role($u, $this->a, 'brother');
        $this->a->forceFill(['brothers_treasury_visible' => true])->save();
        $this->select($u, $this->a);
        $this->get('/dashboard')->assertOk()->assertSee('Totali condivisi dalla loggia')->assertSee('1175,00')->assertDontSee('Banca Fittizia')->assertDontSee('Operazione interamente fittizia');
        $this->get(route('reports.show', [$this->a->id, 'balances']))->assertForbidden();
    }

    public function test_global_super_dashboard_reads_no_private_module_tables(): void
    {
        $this->fixture();
        $u = User::factory()->create(['is_platform_admin' => true]);
        $this->select($u, $this->a);
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $this->get('/dashboard')->assertOk()->assertSee('Panoramica piattaforma')->assertDontSee('Banca Fittizia')->assertDontSee('NomeFittizio')->assertDontSee('1295,00');
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:from|join)\s+["`]?(treasury_|capitation_|levy_obligations|member_people)/i', $query);
        }
        $this->get(route('reports.show', [$this->a->id, 'financial']))->assertStatus(409);
    }

    public function test_user_without_tenant_gets_selection_instead_of_private_dashboard(): void
    {
        session()->forget('active_lodge_id');
        $this->get('/dashboard')->assertOk()->assertSee('Seleziona una loggia autorizzata');
    }

    public function test_tenant_change_forged_url_and_filters_cannot_cross_scope(): void
    {
        $this->fixture();
        $this->get(route('reports.show', [$this->b->id, 'capitations']))->assertStatus(409);
        $this->select($this->user, $this->b);
        $d = app(ReportBuilder::class)->make($this->b->id, 'capitations', ['lodge_id' => $this->a->id, 'person_id' => 1, 'from' => '2026-10-01', 'to' => '2026-10-15']);
        $this->assertSame(0, $d->metrics['Dovuto']);
        $this->assertSame([], $d->rows);
        $this->get(route('reports.dashboard', $this->a->id))->assertStatus(409);
    }

    public function test_export_values_and_private_headers_match_screen_document(): void
    {
        $this->fixture();
        $d = $this->report('financial');
        $csv = $this->get(route('reports.export', [$this->a->id, 'financial', 'csv', 'from' => '2026-10-01', 'to' => '2026-10-15']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('1295,00', $csv->getContent());
        $this->assertStringContainsString('01/10/2026', $csv->getContent());
        $this->get(route('reports.show', [$this->a->id, 'financial', 'from' => '2026-10-01', 'to' => '2026-10-15']))->assertOk()->assertSee('1295,00');
        $pdf = $this->get(route('reports.export', [$this->a->id, 'financial', 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_csv_neutralizes_user_supplied_formula_cells(): void
    {
        $this->account('=FORMULA_FITTIZIA', 'bank', '0.00');
        $r = $this->get(route('reports.export', [$this->a->id, 'balances', 'csv']))->assertOk();
        $this->assertStringContainsString("'=FORMULA_FITTIZIA", $r->getContent());
    }

    public function test_dates_and_years_are_validated_without_silent_fallback(): void
    {
        foreach ([['from' => '2026-10-16'], ['from' => '2026-10-10', 'to' => '2026-10-01'], ['from' => 'invalid']] as $input) {
            try {
                $this->report('financial', $input);
                $this->fail();
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        app(ReportBuilder::class)->make($this->a->id, 'annual', ['year' => 2027]);
    }

    public function test_all_report_screens_render_with_italian_dates_and_euros(): void
    {
        $this->fixture();
        foreach (['financial', 'categories', 'balances', 'capitations', 'arrears', 'levies', 'annual'] as $kind) {
            $this->get(route('reports.show', [$this->a->id, $kind]))->assertOk()->assertSee('€');
        }
    }

    public function test_technical_dashboard_reports_unknown_recent_and_stale_heartbeat(): void
    {
        $this->user->forceFill(['is_platform_admin' => true])->save();
        $this->select($this->user, $this->a);
        $runtime = app(Runtime::class);
        $this->assertSame('Non rilevato', $runtime->platform()['scheduler_state']);
        Runtime::tick();
        $this->assertSame('Segnale recente', $runtime->platform()['scheduler_state']);
        $this->travel(6)->minutes();
        $this->assertSame('Segnale in ritardo', $runtime->platform()['scheduler_state']);
    }

    public function test_invalid_otp_and_removed_roles_block_dashboard_and_export(): void
    {
        session()->forget('auth.second_factor_user_id');
        $this->get('/dashboard')->assertForbidden();
        $this->select($this->user, $this->a);
        DB::table('lodge_user_roles')->where('user_id', $this->user->id)->delete();
        $this->get(route('reports.export', [$this->a->id, 'financial', 'csv']))->assertStatus(409);
    }

    public function test_personal_report_cannot_be_redirected_to_another_account_or_lodge(): void
    {
        $f = $this->fixture();
        $u = User::factory()->create();
        $f['person']->forceFill(['user_id' => $u->id])->save();
        $this->role($u, $this->a, 'brother');
        $this->role($u, $this->b, 'brother');
        $this->select($u, $this->b);
        $d = app(ReportBuilder::class)->make($this->b->id, 'personal', ['person_id' => $f['person']->id, 'user_id' => $this->user->id]);
        $this->assertSame(0, $d->metrics['Dovuto']);
        $this->assertSame([], $d->rows);
        $this->get(route('reports.show', [$this->a->id, 'personal']))->assertStatus(409);
    }

    public function test_personal_cash_received_is_distinct_from_allocated_amount_and_credit(): void
    {
        $f = $this->fixture();
        $f['person']->forceFill(['user_id' => $this->user->id])->save();
        $d = app(Dashboards::class)->brother($this->a->id, new Period('2026-10-01', '2026-10-15'));
        $this->assertSame(6000, $d['netReceived']);
        $this->assertSame(4000, $d['totals']['paid']);
        $this->assertSame(2000, $d['credit']);
    }

    public function test_personal_refund_uses_reversal_accounting_date_without_rewriting_old_cash_flow(): void
    {
        $f = $this->fixture();
        $f['person']->forceFill(['user_id' => $this->user->id])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'Europe/Rome'));
        $this->select($this->user, $this->a);
        $op = Operation::query()->where('public_id', $f['receipt']->operation_reference)->firstOrFail();
        $this->ledger->reverse($this->a->id, $op->id, '2026-11-01', 'Storno personale Fittizio nel mese seguente', $this->key());
        $old = app(Dashboards::class)->brother($this->a->id, new Period('2026-10-01', '2026-10-15'));
        $new = app(Dashboards::class)->brother($this->a->id, new Period('2026-11-01', '2026-11-02'));
        $this->assertSame(6000, $old['netReceived']);
        $this->assertSame(-6000, $new['netReceived']);
        $this->assertSame(0, $new['credit']);
        $this->assertCount(1, $new['history']);
        $this->assertSame('2026-11-01', $new['history'][0]['date']);
        $flow = app(Sources::class)->finance($this->a->id, new Period('2026-11-01', '2026-11-02'));
        $this->assertSame(-6000, $flow['income']);
        $this->assertSame(123500, $flow['closing']);
    }

    public function test_report_permission_is_required_even_if_read_and_export_are_allowed(): void
    {
        config(['identity.roles.administrator' => ['read', 'create', 'export']]);
        $d = app(Dashboards::class)->staff($this->a->id, new Period('2026-10-01', '2026-10-15'));
        $this->assertFalse($d['canReports']);
        $this->assertNull($d['flow']);
        $this->get(route('reports.dashboard', $this->a->id))->assertOk()->assertDontSee('Entrate e uscite del periodo');
        $this->get(route('reports.show', [$this->a->id, 'financial']))->assertForbidden();
        $this->get(route('reports.export', [$this->a->id, 'financial', 'csv']))->assertForbidden();
    }

    public function test_own_upcoming_dates_exclude_other_people_and_paid_rates(): void
    {
        $f = $this->fixture();
        $other = app(Members::class)->create($this->a->id, ['reference' => 'OTHER_REPORT', 'first_name' => 'ALTRA_PERSONA_SEGRETA', 'last_name' => 'CognomeFittizio', 'starts_on' => '2026-01-01', 'status' => 'active', 'administrative_exemption' => false]);
        app(Plans::class)->create($this->a->id, ['description' => 'Scadenza futura Fittizia', 'amount' => '20.00', 'periodicity' => 'monthly', 'starts_on' => '2026-10-01', 'due_basis' => 'start', 'due_days' => 20, 'prorata' => 'none', 'is_active' => true]);
        app(CapGenerator::class)->run($this->a->id, '2026-10-15');
        $f['person']->forceFill(['user_id' => $this->user->id])->save();
        $d = app(Dashboards::class)->brother($this->a->id, new Period('2026-10-01', '2026-10-15'));
        $this->assertCount(1, $d['next']);
        $this->assertSame('2026-10-21', $d['next'][0]['due_on']);
        $this->assertSame(2000, $d['next'][0]['amount']);
    }

    public function test_another_authorized_lodge_never_enters_selected_lodge_totals(): void
    {
        $this->fixture();
        $this->select($this->user, $this->b);
        $this->account('CONTO_ALTRA_LOGGIA_SEGRETO', 'bank', '99999.00');
        $this->select($this->user, $this->a);
        $d = $this->report('financial');
        $this->assertSame(129500, $d->metrics['Saldo finale periodo']);
        $this->assertCount(2, $d->rows);
        $this->get(route('reports.export', [$this->a->id, 'balances', 'csv']))->assertOk()->assertDontSee('CONTO_ALTRA_LOGGIA_SEGRETO');
    }

    public function test_historical_year_before_account_opening_is_empty_financially(): void
    {
        $this->fixture();
        $d = app(ReportBuilder::class)->make($this->a->id, 'annual', ['year' => '2025']);
        $this->assertCount(12, $d->rows);
        $this->assertSame(0, $d->metrics['Saldo finale periodo']);
        $this->assertSame(0, array_sum(array_column($d->rows, 'income')));
        $this->assertSame(0, array_sum(array_column($d->rows, 'equity')));
    }

    public function test_technical_dashboard_counts_no_local_financial_audit_in_global_activity(): void
    {
        $this->user->forceFill(['is_platform_admin' => true])->save();
        $this->select($this->user, $this->a);
        Audit::record('treasury.posted', $this->user->id, $this->a->id, metadata: ['secret' => 'SEGRETO_LOCALE']);
        $d = app(Runtime::class)->platform();
        $this->assertSame(2, $d['lodges']);
        $this->assertSame(1, $d['obediences']);
        $this->assertStringNotContainsString('SEGRETO_LOCALE', json_encode($d, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Operazione finanziaria contabilizzata', json_encode($d, JSON_THROW_ON_ERROR));
    }

    public function test_unknown_report_and_format_are_not_routed(): void
    {
        $this->get('/logge/'.$this->a->id.'/report/unknown')->assertNotFound();
        $this->get('/logge/'.$this->a->id.'/report/financial/esporta/html')->assertNotFound();
    }
}
