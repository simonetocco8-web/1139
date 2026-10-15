<?php

namespace Tests\Feature;

use App\Domains\Capitations\Actions\Balances as CapBalances;
use App\Domains\Capitations\Actions\Generator as CapGenerator;
use App\Domains\Capitations\Actions\Payments as Capit;
use App\Domains\Capitations\Actions\Plans;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Levies\Actions\Balances as LevyBalances;
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
use App\Domains\Treasury\Actions\Attachments;
use App\Domains\Treasury\Actions\Balances;
use App\Domains\Treasury\Actions\Banking;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Adapters\LedgerReceiptGateway;
use App\Domains\Treasury\Contracts\ReceiptGateway;
use App\Domains\Treasury\Data\PostingReference;
use App\Domains\Treasury\Data\ReceiptInput;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Line;
use App\Domains\Treasury\Models\Operation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TreasuryDemoSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TreasuryTest extends TestCase
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

    public function test_opening_balances_are_balanced_and_immutable(): void
    {
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame(10000, Balances::account($this->a->id, $this->cash->id));
        $this->assertDatabaseCount('treasury_operations', 2);
        foreach (Operation::all() as $op) {
            $this->assertEquals(0, $op->lines()->sum('amount'));
        }$this->expectException(\LogicException::class);
        $this->bank->update(['opening_amount' => '1.00']);
    }

    public function test_opening_retry_is_idempotent(): void
    {
        $key = $this->key();
        $a = $this->account('Nuova Fittizia', 'bank', '0.00', key: $key);
        $b = $this->account('Nuova Fittizia', 'bank', '0.00', key: $key);
        $this->assertSame($a->id, $b->id);
        $this->assertDatabaseCount('treasury_operations', 3);
    }

    public function test_negative_bank_opening_allowed_cash_forbidden(): void
    {
        $a = $this->account('Banca Scoperta Fittizia', 'bank', '-20.00');
        $this->assertSame(-2000, Balances::account($this->a->id, $a->id));
        $this->expectException(ValidationException::class);
        $this->account('Cassa Non Valida', 'cash', '-1.00');
    }

    public function test_income_expense_and_idempotency(): void
    {
        $key = $this->key();
        $one = $this->financial('income', '20.00', key: $key);
        $again = $this->financial('income', '20.00', key: $key);
        $this->assertSame($one->id, $again->id);
        $this->financial('expense', '5.50');
        $this->assertSame(101450, Balances::account($this->a->id, $this->bank->id));
        $this->assertDatabaseCount('treasury_operations', 4);
    }

    public function test_key_conflict_rejected(): void
    {
        $key = $this->key();
        $this->financial('income', '20.00', key: $key);
        $this->expectException(HttpException::class);
        $this->financial('income', '21.00', key: $key);
    }

    public function test_cash_and_bank_transfer_conserve_consolidated_balance(): void
    {
        $op = $this->financial('transfer', '50.00', extra: ['target_id' => $this->cash->id]);
        $this->assertEquals(0, $op->lines()->sum('amount'));
        $this->assertSame(95000, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame(15000, Balances::account($this->a->id, $this->cash->id));
        $this->assertSame(0, Line::query()->where('operation_id', $op->id)->whereIn('bucket', ['income', 'expense'])->count());
    }

    public function test_transfer_between_banks_and_reversal(): void
    {
        $second = $this->account('Seconda Banca Fittizia', 'bank', '0.00');
        $op = $this->financial('transfer', '90.00', extra: ['target_id' => $second->id]);
        $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Storno trasferimento Fittizio', $this->key());
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame(0, Balances::account($this->a->id, $second->id));
    }

    public function test_negative_cash_spend_is_rejected_atomically(): void
    {
        $before = Operation::count();
        try {
            $this->financial('expense', '100.01', $this->cash);
            $this->fail();
        } catch (ValidationException) {
            $this->assertSame($before, Operation::count());
            $this->assertSame(10000, Balances::account($this->a->id, $this->cash->id));
        }
    }

    public function test_backdated_spend_cannot_create_historical_negative_cash(): void
    {
        $this->financial('income', '100.00', $this->cash, extra: ['booked_on' => '2026-10-10']);
        $this->financial('expense', '150.00', $this->cash, extra: ['booked_on' => '2026-10-11']);
        try {
            $this->financial('expense', '80.00', $this->cash, extra: ['booked_on' => '2026-10-01']);
            $this->fail();
        } catch (ValidationException) {
            $this->assertSame(5000, Balances::account($this->a->id, $this->cash->id));
        }
    }

    public function test_entry_before_opening_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->financial('income', '10.00', extra: ['booked_on' => '2025-12-31']);
    }

    public function test_adjustment_requires_reverse_permission_and_has_no_economic_effect(): void
    {
        $op = $this->financial('adjustment', '-5.00');
        $this->assertSame(99500, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame(1, $op->lines()->where('bucket', 'equity')->count());
        $this->assertSame(0, $op->lines()->whereIn('bucket', ['income', 'expense'])->count());
    }

    public function test_partial_capitation_updates_finance_and_installment_atomically(): void
    {
        $p = $this->person();
        $i = $this->installment($p);
        $key = $this->key();
        $receipt = app(Capit::class)->receive($this->a->id, $p->id, '60.00', '2026-10-15', (string) $this->bank->id, $key, [$i->id => '40.00']);
        $again = app(Capit::class)->receive($this->a->id, $p->id, '60.00', '2026-10-15', (string) $this->bank->id, $key, [$i->id => '40.00']);
        $this->assertSame($receipt->id, $again->id);
        $this->assertSame(106000, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame(6000, CapBalances::installment($i->fresh())['residual']);
        $this->assertSame(2000, CapBalances::credit($receipt));
        $this->assertDatabaseHas('treasury_source_links', ['receipt_id' => $receipt->id]);
        app(Capit::class)->allocate($this->a->id, $p->id, $receipt->id, $this->key(), [$i->id => '20.00']);
        $this->assertSame(106000, Balances::account($this->a->id, $this->bank->id));
    }

    public function test_invalid_allocation_rolls_back_cash_receipt(): void
    {
        $p = $this->person();
        $i = $this->installment($p);
        try {
            app(Capit::class)->receive($this->a->id, $p->id, '60.00', '2026-10-15', (string) $this->bank->id, $this->key(), [$i->id => '61.00']);
            $this->fail();
        } catch (ValidationException) {
            $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
            $this->assertDatabaseCount('capitation_receipts', 0);
            $this->assertDatabaseCount('treasury_operations', 2);
        }
    }

    public function test_receipt_reversal_reopens_rates_and_eliminates_available_credit(): void
    {
        $p = $this->person();
        $i = $this->installment($p);
        $receipt = app(Capit::class)->receive($this->a->id, $p->id, '120.00', '2026-10-15', (string) $this->bank->id, $this->key(), [$i->id => '100.00']);
        $op = Operation::query()->where('public_id', $receipt->operation_reference)->firstOrFail();
        $key = $this->key();
        $rev = $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Incasso Fittizio annullato', $key);
        $again = $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Incasso Fittizio annullato', $key);
        $this->assertSame($rev->id, $again->id);
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame(10000, CapBalances::installment($i->fresh())['residual']);
        $this->assertSame(0, CapBalances::credit($receipt));
        $this->assertTrue($receipt->isVoided());
        $this->expectException(HttpException::class);
        app(Capit::class)->allocate($this->a->id, $p->id, $receipt->id, $this->key(), [$i->id => '1.00']);
    }

    public function test_partial_levy_and_reversal_reopen_obligation(): void
    {
        $due = $this->levy();
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        $p = app(Levies::class)->pay($this->a->id, $due->id, '40.00', '2026-10-15', (string) $this->bank->id, $this->key());
        $this->assertSame(96000, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame(6000, LevyBalances::of($due->fresh())['residual']);
        $op = Operation::query()->where('public_id', $p->operation_reference)->firstOrFail();
        $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Pagamento Fittizio annullato', $this->key());
        $this->assertSame(10000, LevyBalances::of($due->fresh())['residual']);
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
    }

    public function test_receipt_reversal_cannot_overdraw_cash(): void
    {
        $p = $this->person();
        $r = app(Capit::class)->receive($this->a->id, $p->id, '50.00', '2026-10-15', (string) $this->cash->id, $this->key());
        $this->financial('expense', '140.00', $this->cash);
        $op = Operation::query()->where('public_id', $r->operation_reference)->firstOrFail();
        try {
            $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Storno Fittizio impossibile', $this->key());
            $this->fail();
        } catch (ValidationException) {
            $this->assertFalse($r->isVoided());
            $this->assertSame(1000, Balances::account($this->a->id, $this->cash->id));
        }
    }

    public function test_storno_of_storno_and_duplicate_storno_are_forbidden(): void
    {
        $op = $this->financial('expense', '5.00');
        $rev = $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Storno Fittizio singolo', $this->key());
        $this->expectException(HttpException::class);
        $this->ledger->reverse($this->a->id, $rev->id, '2026-10-15', 'Storno di storno Fittizio', $this->key());
    }

    public function test_reconciliation_of_existing_receipt_does_not_post_income_twice(): void
    {
        $op = $this->financial('income', '25.00');
        $banking = app(Banking::class);
        $entry = $banking->entry($this->a->id, $this->bank->id, $this->row());
        $before = Operation::count();
        $line = $op->lines()->where('account_id', $this->bank->id)->firstOrFail();
        $rec = $banking->match($this->a->id, $entry->id, $line->id);
        $again = $banking->match($this->a->id, $entry->id, $line->id);
        $this->assertSame($rec->id, $again->id);
        $this->assertSame($before, Operation::count());
        $this->assertSame(102500, Balances::account($this->a->id, $this->bank->id));
    }

    public function test_approval_blocks_existing_candidate_in_same_date(): void
    {
        $this->financial('income', '25.00');
        $b = app(Banking::class);
        $e = $b->entry($this->a->id, $this->bank->id, $this->row());
        $this->expectException(HttpException::class);
        $b->approve($this->a->id, $e->id, $this->income->id, $this->key());
    }

    public function test_approved_new_bank_entry_posts_once_and_matches(): void
    {
        $b = app(Banking::class);
        $e = $b->entry($this->a->id, $this->bank->id, $this->row());
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        $rec = $b->approve($this->a->id, $e->id, $this->income->id, $this->key());
        $b->approve($this->a->id, $e->id, $this->income->id, $this->key());
        $this->assertSame(102500, Balances::account($this->a->id, $this->bank->id));
        $this->assertDatabaseCount('treasury_reconciliations', 1);
    }

    public function test_reconciled_posting_requires_release_before_reversal(): void
    {
        $op = $this->financial('income', '25.00');
        $b = app(Banking::class);
        $e = $b->entry($this->a->id, $this->bank->id, $this->row());
        $rec = $b->match($this->a->id, $e->id, $op->lines()->whereNotNull('account_id')->firstOrFail()->id);
        try {
            $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Storno Fittizio', $this->key());
            $this->fail();
        } catch (HttpException $ex) {
            $this->assertSame(409, $ex->getStatusCode());
        }$b->release($this->a->id, $rec->id, 'Abbinamento Fittizio da annullare');
        $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Storno Fittizio', $this->key());
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        $this->assertDatabaseHas('treasury_reconciliations', ['id' => $rec->id, 'released_by' => $this->user->id]);
    }

    public function test_csv_preview_duplicate_detection_and_idempotent_confirmation(): void
    {
        $b = app(Banking::class);
        $csv = "booked_on;amount;description;reference\n2026-10-15;25.00;Fittizio uno;REF_1\n2026-10-15;25.00;Fittizio uno;REF_1\n2026-10-15;-5.00;Fittizio due;REF_2\n";
        $preview = $b->preview($this->a->id, $this->bank->id, $csv);
        $this->assertTrue($preview['batch']->payload['rows'][1]['duplicate']);
        $this->assertDatabaseCount('treasury_statement_entries', 0);
        $this->assertSame(2, $b->confirm($this->a->id, $preview['batch']->id, $preview['token']));
        $this->assertSame(2, $b->confirm($this->a->id, $preview['batch']->id, $preview['token']));
        $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        $this->assertDatabaseCount('treasury_operations', 2);
        $this->assertStringNotContainsString('Fittizio uno', DB::table('treasury_imports')->value('payload'));
    }

    public function test_preview_expiry_and_other_session_rejected(): void
    {
        $b = app(Banking::class);
        $p = $b->preview($this->a->id, $this->bank->id, "booked_on;amount;description;reference\n2026-10-15;5.00;Fittizio;REF\n");
        session()->put('treasury.import_nonce', 'changed');
        $this->expectException(HttpException::class);
        $b->confirm($this->a->id, $p['batch']->id, $p['token']);
    }

    public function test_invalid_preview_cannot_be_confirmed(): void
    {
        $b = app(Banking::class);
        $p = $b->preview($this->a->id, $this->bank->id, "booked_on;amount;description;reference\n2026-99-01;5.00;Fittizio;REF\n");
        $this->assertNotEmpty($p['batch']->payload['errors']);
        $this->expectException(HttpException::class);
        $b->confirm($this->a->id, $p['batch']->id, $p['token']);
    }

    public function test_account_and_amount_must_match_bank_line(): void
    {
        $op = $this->financial('income', '26.00');
        $b = app(Banking::class);
        $e = $b->entry($this->a->id, $this->bank->id, $this->row());
        $this->expectException(ModelNotFoundException::class);
        $b->match($this->a->id, $e->id, $op->lines()->whereNotNull('account_id')->firstOrFail()->id);
    }

    public function test_csv_conflicting_reference_is_not_silently_replaced(): void
    {
        $b = app(Banking::class);
        $b->entry($this->a->id, $this->bank->id, $this->row());
        $this->expectException(HttpException::class);
        $b->entry($this->a->id, $this->bank->id, $this->row(['amount' => '26.00']));
    }

    public function test_private_attachment_is_encrypted_and_authorized(): void
    {
        Storage::fake('local');
        $op = $this->financial('income', '2.00');
        $file = UploadedFile::fake()->createWithContent('prova.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
        $a = app(Attachments::class)->upload($this->a->id, $op->id, $file);
        $this->assertStringNotContainsString('%PDF', Storage::disk('local')->get($a->path));
        $this->get(route('treasury.download', [$this->a->id, $a->id]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee('%PDF', false);
        $this->select($this->user, $this->b);
        $this->get(route('treasury.download', [$this->b->id, $a->id]))->assertNotFound();
    }

    public function test_executable_attachment_is_rejected(): void
    {
        $op = $this->financial('income', '2.00');
        $this->expectException(ValidationException::class);
        app(Attachments::class)->upload($this->a->id, $op->id, UploadedFile::fake()->createWithContent('attacco.php', '<?php echo 1;'));
    }

    public function test_foreign_tenant_ids_are_denied_in_postings_and_urls(): void
    {
        $op = $this->financial('income', '10.00');
        $this->select($this->user, $this->b);
        $foreign = $this->account('Banca Altra Fittizia', 'bank', '0.00');
        $this->get(route('treasury.detail', [$this->b->id, $op->id]))->assertNotFound();
        $this->get(route('treasury.detail', [$this->a->id, $op->id]))->assertStatus(409);
        $this->select($this->user, $this->a);
        $this->expectException(ModelNotFoundException::class);
        $this->financial('transfer', '1.00', extra: ['target_id' => $foreign->id]);
    }

    public function test_brother_and_global_super_have_no_financial_access(): void
    {
        $u = User::factory()->create();
        $this->role($u, $this->a, 'brother');
        $this->select($u, $this->a);
        foreach (['treasury.index', 'treasury.accounts', 'treasury.form'] as $route) {
            $this->get(route($route))->assertForbidden();
        }$this->get(route('treasury.export', 'csv'))->assertForbidden();
        $u = User::factory()->create(['is_platform_admin' => true]);
        $this->select($u, $this->a);
        $this->get(route('treasury.index'))->assertForbidden();
    }

    public function test_private_register_filters_exports_csv_and_pdf(): void
    {
        $op = $this->financial('income', '25.00', extra: ['description' => '=FORMULA_FITTIZIA']);
        $this->get(route('treasury.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('1125,00');
        $this->get(route('treasury.index', ['kind' => 'transfer']))->assertOk()->assertDontSee('=FORMULA_FITTIZIA');
        $this->get(route('treasury.accounts'))->assertOk();
        $this->get(route('treasury.form'))->assertOk();
        $this->get(route('treasury.detail', [$this->a->id, $op->id]))->assertOk();
        $this->get(route('treasury.bank', [$this->a->id, $this->bank->id]))->assertOk();
        $csv = $this->get(route('treasury.export', 'csv'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=FORMULA_FITTIZIA", $csv->getContent());
        $pdf = $this->get(route('treasury.export', 'pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_confirmation_otp_and_server_permissions_required(): void
    {
        $this->post(route('treasury.post', $this->a->id), [])->assertSessionHasErrors('confirmation');
        session()->forget('auth.second_factor_user_id');
        $this->get(route('treasury.index'))->assertForbidden();
    }

    public function test_database_rejects_line_to_another_tenant_account(): void
    {
        $op = $this->financial('income', '5.00');
        $this->select($this->user, $this->b);
        $a = $this->account('Altra Banca Fittizia', 'bank', '0.00');
        $this->expectException(QueryException::class);
        Line::query()->create(['lodge_id' => $this->a->id, 'operation_id' => $op->id, 'account_id' => $a->id, 'bucket' => 'financial', 'amount' => '5.00']);
    }

    public function test_posted_operations_and_lines_cannot_be_deleted(): void
    {
        $op = $this->financial('income', '5.00');
        $this->expectException(\LogicException::class);
        $op->lines()->firstOrFail()->delete();
    }

    public function test_real_gateway_is_enabled_and_accounts_are_scoped(): void
    {
        $this->assertInstanceOf(LedgerReceiptGateway::class, app(ReceiptGateway::class));
        $this->assertTrue(app(ReceiptGateway::class)->available());
        $this->assertSame([['id' => (string) $this->bank->id, 'label' => $this->bank->name], ['id' => (string) $this->cash->id, 'label' => $this->cash->name]], app(ReceiptGateway::class)->accounts($this->a->id));
    }

    public function test_real_financial_post_rolls_back_if_caller_fails_after_post(): void
    {
        $person = $this->person();
        $real = app(ReceiptGateway::class);
        app()->instance(ReceiptGateway::class, new class($real) implements ReceiptGateway
        {
            public function __construct(private ReceiptGateway $real) {}

            public function available(): bool
            {
                return true;
            }

            public function accounts(int $id): array
            {
                return $this->real->accounts($id);
            }

            public function post(ReceiptInput $input, ConnectionInterface $connection): PostingReference
            {
                $this->real->post($input, $connection);
                throw new \RuntimeException('Fallimento dopo registrazione reale di test.');
            }
        });
        try {
            app(Capit::class)->receive($this->a->id, $person->id, '20.00', '2026-10-15', (string) $this->bank->id, $this->key());
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('treasury_operations', 2);
            $this->assertDatabaseCount('treasury_lines', 4);
            $this->assertDatabaseCount('capitation_receipts', 0);
            $this->assertSame(100000, Balances::account($this->a->id, $this->bank->id));
        }
    }

    public function test_demo_is_idempotent_balanced_and_has_no_accounts_for_login(): void
    {
        $before = User::count();
        $this->seed(TreasuryDemoSeeder::class);
        $count = Operation::count();
        $this->seed(TreasuryDemoSeeder::class);
        $this->assertSame($count, Operation::count());
        $this->assertSame($before, User::count());
        foreach (Operation::all() as $op) {
            $this->assertEquals(0, $op->lines()->sum('amount'));
        }
    }

    public function test_preview_expiration_is_enforced(): void
    {
        $b = app(Banking::class);
        $p = $b->preview($this->a->id, $this->bank->id, "booked_on;amount;description;reference\n2026-10-15;5.00;Fittizio;REF\n");
        $this->travel(16)->minutes();
        $this->select($this->user, $this->a);
        $this->expectException(HttpException::class);
        $b->confirm($this->a->id, $p['batch']->id, $p['token']);
    }

    public function test_foreign_category_and_bank_rows_are_rejected(): void
    {
        $this->select($this->user, $this->b);
        $c = $this->ledger->category($this->b->id, 'Categoria Altrui Fittizia', 'income');
        $this->select($this->user, $this->a);
        $this->expectException(ModelNotFoundException::class);
        $this->financial('income', '1.00', extra: ['category_id' => $c->id]);
    }

    public function test_brother_can_only_read_explicitly_shared_aggregates(): void
    {
        $u = User::factory()->create();
        $this->role($u, $this->a, 'brother');
        $this->select($u, $this->a);
        $this->get(route('treasury.summary'))->assertForbidden();
        $this->a->forceFill(['brothers_treasury_visible' => true])->save();
        $this->get(route('treasury.summary'))->assertOk()->assertSee('1100,00')->assertDontSee('Banca Fittizia')->assertDontSee('Entrata Fittizia');
        $this->get(route('treasury.index'))->assertForbidden();
        $this->get(route('treasury.export', 'csv'))->assertForbidden();
    }

    public function test_bank_date_difference_does_not_allow_duplicate_receipt(): void
    {
        $this->financial('income', '25.00', extra: ['booked_on' => '2026-10-14']);
        $b = app(Banking::class);
        $e = $b->entry($this->a->id, $this->bank->id, $this->row());
        $this->expectException(HttpException::class);
        $b->approve($this->a->id, $e->id, $this->income->id, $this->key());
    }

    public function test_opening_can_be_reversed_without_editing_its_original_amount(): void
    {
        $op = Operation::query()->where('lodge_id', $this->a->id)->where('kind', 'opening')->whereHas('lines', fn ($q) => $q->where('account_id', $this->bank->id))->firstOrFail();
        $this->ledger->reverse($this->a->id, $op->id, '2026-10-15', 'Saldo iniziale Fittizio errato', $this->key());
        $this->assertSame(0, Balances::account($this->a->id, $this->bank->id));
        $this->assertSame('1000.00', $this->bank->fresh()->opening_amount);
    }

    public function test_similar_bank_rows_with_distinct_references_are_flagged_and_preserved(): void
    {
        $b = app(Banking::class);
        $p = $b->preview($this->a->id, $this->bank->id, "booked_on;amount;description;reference\n2026-10-15;25.00;Fittizio uguale;REF_A\n2026-10-15;25.00;Fittizio uguale;REF_B\n");
        $this->assertFalse($p['batch']->payload['rows'][1]['duplicate']);
        $this->assertTrue($p['batch']->payload['rows'][1]['similar']);
        $this->assertSame(2, $b->confirm($this->a->id, $p['batch']->id, $p['token']));
        $this->assertDatabaseCount('treasury_statement_entries', 2);
        $this->assertDatabaseCount('treasury_operations', 2);
    }
}
