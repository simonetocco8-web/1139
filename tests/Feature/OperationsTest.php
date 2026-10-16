<?php

namespace Tests\Feature;

use App\Domains\Capitations\Actions\Generator as CapGenerator;
use App\Domains\Capitations\Actions\Payments as Capit;
use App\Domains\Capitations\Actions\Plans;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Identity\Delivery\FakeTransport;
use App\Domains\Levies\Actions\Generator as LevyGenerator;
use App\Domains\Levies\Actions\Payments as Levies;
use App\Domains\Levies\Actions\Rules;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Membership\Actions\Members;
use App\Domains\Membership\Models\Person;
use App\Domains\Notifications\Delivery;
use App\Domains\Notifications\Outbox;
use App\Domains\Notifications\Preferences;
use App\Domains\Notifications\Producer;
use App\Domains\Operations\EncryptedArchive;
use App\Domains\Operations\Health;
use App\Domains\Operations\Integrity;
use App\Domains\Operations\Retention;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use App\Domains\Privacy\Cases;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Operation;
use App\Jobs\DeliverCommunication;
use App\Jobs\GenerateCapitations;
use App\Jobs\GenerateLevies;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationsTest extends TestCase
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

    private function enabled(): void
    {
        config(['communications.enabled' => true, 'identity.delivery_driver' => 'fake']);
        foreach (array_keys(config('communications.types')) as $type) {
            if ($type !== 'technical') {
                DB::table('communication_rules')->insert(['lodge_id' => $this->a->id, 'type' => $type, 'enabled' => true]);
            }
        }
    }

    private function linked(): array
    {
        $this->enabled();
        $p = $this->person();
        $rate = $this->installment($p);
        $u = User::factory()->create(['name' => 'Destinatario Completamente Fittizio']);
        $this->role($u, $this->a, 'brother');
        $p->forceFill(['user_id' => $u->id])->save();

        return [$p, $rate, $u];
    }

    private function outbox(string $type, User $u, ?int $source = null): Outbox
    {
        app(Producer::class)->enqueue($this->a->id, $u, $type, $source, 'fixture');

        return Outbox::query()->where('user_id', $u->id)->where('type', $type)->latest('id')->firstOrFail();
    }

    public function test_notifications_default_disabled_and_lodge_opt_in_is_required(): void
    {
        $p = $this->person();
        $this->installment($p);
        $p->forceFill(['user_id' => $this->user->id])->save();
        $producer = app(Producer::class);
        $this->assertSame(0, $producer->reminders());
        config(['communications.enabled' => true]);
        $this->assertSame(0, $producer->reminders());
        $this->assertDatabaseCount('communication_outbox', 0);
    }

    public function test_reminders_are_deduplicated_and_only_for_the_own_person(): void
    {
        [$p,$rate,$u] = $this->linked();
        $other = app(Members::class)->create($this->a->id, ['reference' => 'OTHER', 'first_name' => 'TerzoFittizio', 'last_name' => 'Fittizio', 'starts_on' => '2026-01-01', 'status' => 'active', 'administrative_exemption' => false]);
        app(CapGenerator::class)->run($this->a->id, '2026-10-15');
        $producer = app(Producer::class);
        $this->assertSame(1, $producer->reminders());
        $this->assertSame(0, $producer->reminders());
        $o = Outbox::query()->sole();
        $this->assertSame($u->id, $o->user_id);
        $this->assertSame($rate->id, $o->source_id);
        $this->assertSame('cap_overdue', $o->type);
        app(Delivery::class)->send($o->id);
        $mail = app(FakeTransport::class)->messages;
        $this->assertCount(1, $mail);
        $this->assertSame($u->email, $mail[0]['destination']);
        $this->assertStringNotContainsString('Loggia', $mail[0]['body']);
        $this->assertStringNotContainsString('100', $mail[0]['body']);
        $this->assertStringNotContainsString($other->first_name, $mail[0]['body']);
    }

    public function test_upcoming_and_overdue_periods_use_distinct_stable_keys(): void
    {
        [$p,$r,$u] = $this->linked();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Rome'));
        $producer = app(Producer::class);
        $this->assertSame(1, $producer->reminders());
        $this->assertDatabaseHas('communication_outbox', ['type' => 'cap_upcoming']);
        $this->assertSame(0, $producer->reminders());
        $this->travel(1)->days();
        $this->assertSame(1, $producer->reminders());
        $this->assertDatabaseHas('communication_outbox', ['type' => 'cap_overdue']);
        $this->assertSame(0, $producer->reminders());
        $this->travel(8)->days();
        $this->assertSame(1, $producer->reminders());
    }

    public function test_paid_rate_is_cancelled_before_delivery(): void
    {
        [$p,$r,$u] = $this->linked();
        $o = $this->outbox('cap_overdue', $u, $r->id);
        app(Capit::class)->receive($this->a->id, $p->id, '100.00', '2026-10-15', (string) $this->bank->id, $this->key(), [$r->id => '100.00']);
        app(Delivery::class)->send($o->id);
        $this->assertSame('cancelled', $o->fresh()->state);
        $this->assertCount(0, app(FakeTransport::class)->messages);
    }

    public function test_revoked_membership_preferences_and_tenant_status_cancel_pending_messages(): void
    {
        [$p,$r,$u] = $this->linked();
        $o = $this->outbox('cap_overdue', $u, $r->id);
        DB::table('lodge_user')->where('user_id', $u->id)->update(['is_active' => false]);
        app(Delivery::class)->send($o->id);
        $this->assertSame('cancelled', $o->fresh()->state);
        DB::table('lodge_user')->where('user_id', $u->id)->update(['is_active' => true]);
        $o = $this->outbox('cap_upcoming', $u, $r->id);
        $this->a->forceFill(['is_active' => false])->save();
        app(Delivery::class)->send($o->id);
        $this->assertSame('cancelled', $o->fresh()->state);
    }

    public function test_preference_changes_are_checked_again_at_send_and_scope_is_local(): void
    {
        [$p,$r,$u] = $this->linked();
        $o = $this->outbox('cap_overdue', $u, $r->id);
        $this->select($u, $this->a);
        app(Preferences::class)->save($this->a->id, 'cap_overdue', false, 'email');
        app(Delivery::class)->send($o->id);
        $this->assertSame('cancelled', $o->fresh()->state);
        $this->assertDatabaseMissing('communication_preferences', ['scope' => 'lodge:'.$this->b->id]);
    }

    public function test_unconfigured_transport_never_claims_success_and_retries_are_bounded(): void
    {
        [$p,$r,$u] = $this->linked();
        config(['identity.delivery_driver' => 'configured', 'mail.mailers.smtp.host' => '']);
        $o = $this->outbox('cap_overdue', $u, $r->id);
        $delivery = app(Delivery::class);
        $delivery->send($o->id);
        $this->assertSame('blocked', $o->fresh()->state);
        $this->assertNull($o->fresh()->accepted_at);
        $this->assertSame(1, $o->fresh()->attempts);
        $delivery->send($o->id);
        $this->assertSame(1, $o->fresh()->attempts);
        $this->travel(16)->minutes();
        $delivery->send($o->id);
        $this->travel(16)->minutes();
        $delivery->send($o->id);
        $this->travel(16)->minutes();
        $delivery->send($o->id);
        $this->assertSame(3, $o->fresh()->attempts);
        $this->assertDatabaseCount('communication_attempts', 3);
    }

    public function test_unknown_transport_outcome_is_not_automatically_retried(): void
    {
        [$p,$r,$u] = $this->linked();
        app(FakeTransport::class)->fail = true;
        $o = $this->outbox('cap_overdue', $u, $r->id);
        $delivery = app(Delivery::class);
        $delivery->send($o->id);
        $this->assertSame('uncertain', $o->fresh()->state);
        $this->travel(20)->minutes();
        $delivery->send($o->id);
        $this->assertSame(1, $o->fresh()->attempts);
        $this->post(route('communications.retry', [$this->a->id, $o->id]), ['confirm' => 1, 'duplicate_risk' => 1])->assertRedirect();
        $this->assertSame('pending', $o->fresh()->state);
    }

    public function test_interrupted_worker_is_marked_uncertain_without_resend(): void
    {
        [$p,$r,$u] = $this->linked();
        $o = $this->outbox('cap_overdue', $u, $r->id);
        $o->forceFill(['state' => 'sending', 'attempts' => 1, 'claimed_at' => now('UTC')->subMinutes(6)])->save();
        DB::table('communication_attempts')->insert(['outbox_id' => $o->id, 'number' => 1, 'outcome' => 'sending', 'code' => 'started', 'occurred_at' => now('UTC')->subMinutes(6)]);
        app(Producer::class)->pump();
        $this->assertSame('uncertain', $o->fresh()->state);
        $this->assertDatabaseHas('communication_attempts', ['outbox_id' => $o->id, 'outcome' => 'uncertain']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_duplicate_jobs_deliver_once_and_queue_contains_only_identifiers(): void
    {
        [$p,$r,$u] = $this->linked();
        $o = $this->outbox('cap_overdue', $u, $r->id);
        app(Producer::class)->pump();
        $this->assertSame(0, app(Producer::class)->pump());
        $this->assertDatabaseCount('jobs', 1);
        $job = DB::table('jobs')->sole();
        $this->assertStringNotContainsString($u->email, $job->payload);
        $this->assertStringNotContainsString($p->first_name, $job->payload);
        $deliver = new DeliverCommunication($o->id);
        $deliver->handle(app(Delivery::class));
        $deliver->handle(app(Delivery::class));
        $this->assertCount(1, app(FakeTransport::class)->messages);
        $this->assertSame('accepted', $o->fresh()->state);
    }

    public function test_sms_requires_verified_current_phone_and_uses_the_configurable_driver(): void
    {
        [$p,$r,$u] = $this->linked();
        $this->select($u, $this->a);
        $this->post(route('communications.preference', $this->a->id), ['type' => 'cap_overdue', 'enabled' => 1, 'channel' => 'sms', 'confirm' => 1])->assertUnprocessable();
        $u->forceFill(['phone' => '+390000000000', 'phone_verified_at' => now('UTC')])->save();
        app(Preferences::class)->save($this->a->id, 'cap_overdue', true, 'sms');
        $o = $this->outbox('cap_overdue', $u, $r->id);
        app(Delivery::class)->send($o->id);
        $messages = app(FakeTransport::class)->messages;
        $this->assertSame('sms', $messages[0]['channel']);
        $this->assertSame('+390000000000', $messages[0]['destination']);
    }

    public function test_levy_reminders_go_only_to_authorized_staff_not_brothers_or_global_super(): void
    {
        [$p,$rate,$brother] = $this->linked();
        $due = $this->levy();
        $super = User::factory()->create(['is_platform_admin' => true]);
        app(Producer::class)->reminders();
        $rows = Outbox::query()->where('type', 'levy_upcoming')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->user->id, $rows[0]->user_id);
        $this->assertDatabaseMissing('communication_outbox', ['user_id' => $super->id]);
        $this->assertDatabaseMissing('communication_outbox', ['type' => 'levy_upcoming', 'user_id' => $brother->id]);
    }

    public function test_administrative_body_is_encrypted_idempotent_and_never_emailed(): void
    {
        [$p,$rate,$u] = $this->linked();
        $body = 'Comunicazione Fittizia <script>alert(1)</script>';
        $key = bin2hex(random_bytes(32));
        $producer = app(Producer::class);
        $this->assertSame(2, $producer->administrative($this->a->id, $body, $key));
        $this->assertSame(0, $producer->administrative($this->a->id, $body, $key));
        $this->assertStringNotContainsString($body, DB::table('communications')->value('body'));
        $o = Outbox::query()->where('user_id', $u->id)->where('type', 'administrative')->sole();
        app(Delivery::class)->send($o->id);
        $this->assertStringNotContainsString($body, app(FakeTransport::class)->messages[0]['body']);
        $this->select($u, $this->a);
        $this->get(route('communications.show', [$this->a->id, $o->id]))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_notification_endpoints_reject_cross_tenant_ids_and_role_escalation(): void
    {
        [$p,$r,$u] = $this->linked();
        $o = $this->outbox('cap_overdue', $u, $r->id);
        $this->select($u, $this->a);
        $this->post(route('communications.rule', $this->a->id), ['type' => 'levy_upcoming', 'enabled' => 1, 'confirm' => 1])->assertForbidden();
        $this->post(route('communications.preference', $this->a->id), ['type' => 'technical', 'enabled' => 1, 'channel' => 'email', 'confirm' => 1])->assertForbidden();
        $this->get(route('communications.show', [$this->b->id, $o->id]))->assertStatus(409);
        $other = $this->outbox('administrative', $this->user, 999);
        $this->get(route('communications.show', [$this->a->id, $other->id]))->assertNotFound();
    }

    public function test_global_history_never_contains_local_communications_and_alerts_deduplicate(): void
    {
        [$p,$r,$u] = $this->linked();
        $this->outbox('cap_overdue', $u, $r->id);
        $super = User::factory()->create(['is_platform_admin' => true]);
        $this->select($super, $this->a);
        $this->assertGreaterThan(0, app(Health::class)->alert());
        $this->assertSame(0, app(Health::class)->alert());
        $this->get(route('communications.platform'))->assertOk()->assertDontSee('Capitazione scaduta')->assertSee('Backup recente non rilevato');
    }

    public function test_privacy_subject_export_is_owned_and_excludes_other_tenants_and_credentials(): void
    {
        [$p,$r,$u] = $this->linked();
        $this->select($u, $this->a);
        $response = $this->get(route('privacy.export', [$this->a->id, $p->id]));
        $response->assertOk()->assertJsonPath('persona.id', $p->id)->assertJsonCount(1, 'rate');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->get(route('privacy.export', [$this->b->id, $p->id]))->assertStatus(409);
        $this->select($this->user, $this->a);
        $other = app(Members::class)->create($this->a->id, ['reference' => 'OTHER', 'first_name' => 'Altro', 'last_name' => 'Fittizio', 'starts_on' => '2026-01-01', 'status' => 'active', 'administrative_exemption' => false]);
        $this->select($u, $this->a);
        $this->get(route('privacy.export', [$this->a->id, $other->id]))->assertForbidden();
    }

    public function test_privacy_requests_are_private_and_foreign_person_ids_are_rejected(): void
    {
        [$p,$r,$u] = $this->linked();
        $this->select($u, $this->a);
        $id = app(Cases::class)->submit($this->a->id, $p->id, 'rectification', 'Rettifica completamente Fittizia');
        $this->assertStringNotContainsString('Fittizia', DB::table('privacy_requests')->where('id', $id)->value('details'));
        $this->get(route('privacy.index', $this->a->id))->assertOk()->assertSee('Rettifica completamente Fittizia');
        $this->get(route('privacy.index', $this->b->id))->assertStatus(409);
    }

    public function test_erasure_has_no_default_policy_and_cannot_delete_financial_history(): void
    {
        [$p,$r,$u] = $this->linked();
        $case = app(Cases::class)->submit($this->a->id, $p->id, 'erasure', 'Richiesta Fittizia di cancellazione');
        $this->post(route('privacy.erase', [$this->a->id, $case]), ['decision' => 'Valutazione Fittizia autorizzata', 'confirm' => 1])->assertUnprocessable();
        config(['operations.policy_reference' => 'POLITICA_FITTIZIA', 'operations.person_retention_days' => 1]);
        DB::table('member_enrollments')->where('person_id', $p->id)->update(['ends_on' => '2026-01-31', 'status' => 'ended']);
        $this->post(route('privacy.erase', [$this->a->id, $case]), ['decision' => 'Valutazione Fittizia autorizzata', 'confirm' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('member_people', ['id' => $p->id]);
        $this->assertDatabaseHas('capitation_installments', ['id' => $r->id]);
    }

    public function test_approved_erasure_removes_only_nonfinancial_closed_person_and_preserves_other_lodges(): void
    {
        $p = $this->person();
        $e = $p->enrollments()->sole();
        app(Members::class)->deactivate($this->a->id, $p->id, $e->id, $e->version, '2026-01-31');
        config(['operations.policy_reference' => 'POLITICA_FITTIZIA', 'operations.person_retention_days' => 30]);
        $cases = app(Cases::class);
        $id = $cases->submit($this->a->id, $p->id, 'erasure', 'Richiesta Fittizia verificata');
        $before = DB::table('treasury_lines')->count();
        $cases->erase($this->a->id, $id, 'Approvazione Fittizia motivata');
        $cases->erase($this->a->id, $id, 'Approvazione Fittizia motivata');
        $this->assertDatabaseMissing('member_people', ['id' => $p->id]);
        $this->assertDatabaseMissing('member_history', ['person_id' => $p->id]);
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'state' => 'erased', 'person_id' => null]);
        $this->assertDatabaseHas('lodges', ['id' => $this->b->id]);
        $this->assertSame($before, DB::table('treasury_lines')->count());
    }

    public function test_technical_retention_is_dry_run_and_requires_approved_policy(): void
    {
        DB::table('password_reset_tokens')->insert(['email' => 'fittizio@example.invalid', 'token' => 'digest', 'created_at' => now('UTC')->subDays(60)]);
        config(['operations.technical_retention_days' => 30]);
        $retention = app(Retention::class);
        $this->assertSame(1, $retention->run()['password_reset_tokens']);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->artisan('operations:prune', ['--apply' => true])->assertFailed();
        $this->assertDatabaseCount('password_reset_tokens', 1);
        config(['operations.policy_reference' => 'POLITICA_FITTIZIA']);
        $retention->run(true);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('treasury_lines', 4);
    }

    public function test_communication_retention_keeps_uncertain_history_and_rejects_short_dedup_window(): void
    {
        [$p,$r,$u] = $this->linked();
        $o = $this->outbox('cap_overdue', $u, $r->id);
        $o->forceFill(['state' => 'uncertain', 'created_at' => now('UTC')->subDays(100)])->save();
        config(['operations.policy_reference' => 'POLITICA_FITTIZIA', 'operations.communication_retention_days' => 31]);
        app(Retention::class)->run(true);
        $this->assertDatabaseHas('communication_outbox', ['id' => $o->id]);
        config(['operations.communication_retention_days' => 1]);
        $this->artisan('operations:prune', ['--apply' => true])->assertFailed();
    }

    public function test_financial_integrity_command_reconciles_real_modules_then_detects_tampering(): void
    {
        $this->fixture();
        $result = app(Integrity::class)->check();
        $this->assertSame([], $result['issues']);
        $this->assertSame(2, $result['checked']);
        $this->artisan('operations:integrity')->assertSuccessful();
        DB::table('treasury_lines')->where('account_id', $this->bank->id)->orderBy('id')->limit(1)->update(['amount' => '1001.00']);
        $this->assertNotEmpty(app(Integrity::class)->check()['issues']);
        $this->artisan('operations:integrity')->assertFailed();
    }

    public function test_queued_generations_contain_no_personal_payload_and_are_idempotent(): void
    {
        Queue::fake();
        $this->artisan('capitation:generate', ['--queued' => true])->assertSuccessful();
        $this->artisan('levy:generate', ['--queued' => true])->assertSuccessful();
        Queue::assertPushed(GenerateCapitations::class, 2);
        Queue::assertPushed(GenerateLevies::class, 2);
        $p = $this->person();
        $r = $this->installment($p);
        $job = new GenerateCapitations($this->a->id, '2026-10-15');
        $job->handle(app(CapGenerator::class));
        $job->handle(app(CapGenerator::class));
        $this->assertDatabaseCount('capitation_installments', 1);
    }

    public function test_production_headers_and_csrf_protect_new_actions(): void
    {
        $this->app->instance('env', 'production');
        $response = $this->get('https://localhost/');
        $response->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Strict-Transport-Security', 'max-age=31536000')->assertHeader('Content-Security-Policy');
        $this->post('https://localhost/logge/'.$this->a->id.'/notifiche/regole', ['type' => 'administrative', 'enabled' => 1, 'confirm' => 1])->assertStatus(419);
    }

    public function test_backup_requires_maintenance_and_never_silently_uses_sqlite(): void
    {
        $this->artisan('operations:backup')->assertFailed();
        $this->assertDatabaseCount('backup_runs', 0);
    }

    public function test_deployment_check_flags_missing_real_configuration_instead_of_readiness(): void
    {
        $issues = app(Health::class)->issues(true);
        $this->assertContains('environment_not_production', $issues);
        $this->assertContains('backup_stale', $issues);
        $this->assertContains('smtp_not_configured', $issues);
        $this->artisan('operations:check', ['--deployment' => true])->assertFailed();
    }

    public function test_production_mail_url_cannot_override_explicit_tls_configuration(): void
    {
        $this->app->instance('env', 'production');
        config(['identity.delivery_driver' => 'configured', 'mail.default' => 'smtp', 'mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.require_tls' => true, 'mail.mailers.smtp.host' => 'smtp.example.invalid', 'mail.mailers.smtp.url' => 'smtp://example.invalid?require_tls=false']);
        $this->assertFalse(app(CodeTransport::class)->available('email'));
    }

    public function test_production_exception_reporting_omits_sensitive_message_and_trace(): void
    {
        $this->app->instance('env', 'production');
        Log::spy();
        app(ExceptionHandler::class)->report(new \RuntimeException('SEGRETO_PERSONALE_FITTIZIO'));
        Log::shouldHaveReceived('error')->once()->with('Errore applicativo. Consultare il registro tecnico autorizzato.', ['exception_class' => \RuntimeException::class]);
    }

    public function test_explicit_retry_can_resume_after_three_configuration_failures(): void
    {
        [$p,$rate,$u] = $this->linked();
        config(['identity.delivery_driver' => 'configured', 'mail.mailers.smtp.host' => '']);
        $o = $this->outbox('cap_overdue', $u, $rate->id);
        $delivery = app(Delivery::class);
        for ($n = 0; $n < 3; $n++) {
            $delivery->send($o->id);
            $this->travel(16)->minutes();
        }
        $this->select($this->user, $this->a);
        config(['identity.delivery_driver' => 'fake']);
        $this->post(route('communications.retry', [$this->a->id, $o->id]), ['confirm' => 1, 'duplicate_risk' => 1])->assertRedirect();
        $delivery->send($o->id);
        $this->assertSame('accepted', $o->fresh()->state);
        $this->assertSame(4, $o->fresh()->attempts);
        $this->assertCount(1, app(FakeTransport::class)->messages);
    }

    public function test_erasure_register_is_encrypted_and_preserves_only_minimal_restore_instructions(): void
    {
        $p = $this->person();
        $e = $p->enrollments()->sole();
        app(Members::class)->deactivate($this->a->id, $p->id, $e->id, $e->version, '2026-01-31');
        config(['operations.policy_reference' => 'POLITICA_FITTIZIA', 'operations.person_retention_days' => 30]);
        $cases = app(Cases::class);
        $id = $cases->submit($this->a->id, $p->id, 'erasure', 'Richiesta Fittizia di cancellazione');
        $cases->erase($this->a->id, $id, 'Approvazione Fittizia verificata');
        $dir = sys_get_temp_dir().'/privacy-register-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $pair = sodium_crypto_box_keypair();
        file_put_contents($dir.'/public.key', base64_encode(sodium_crypto_box_publickey($pair)));
        config(['operations.backup_public_key' => $dir.'/public.key']);
        try {
            $this->artisan('privacy:erasure-register', ['output' => $dir.'/register.mlb'])->assertSuccessful();
            $this->assertStringNotContainsString($p->first_name, file_get_contents($dir.'/register.mlb'));
            EncryptedArchive::extract($dir.'/register.mlb', sodium_crypto_box_secretkey($pair), $dir.'/restore');
            $data = json_decode(file_get_contents($dir.'/restore/private/privacy/erasure-register.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($p->id, $data['events'][0]['person_id']);
            $this->assertSame($this->a->id, $data['events'][0]['lodge_id']);
            $this->assertStringNotContainsString('first_name', json_encode($data));
            file_put_contents($dir.'/secret.key', base64_encode(sodium_crypto_box_secretkey($pair)));
            $this->artisan('operations:restore-check', ['archive' => $dir.'/register.mlb', 'directory' => $dir.'/checked', '--secret-key-file' => $dir.'/secret.key', '--sha256' => hash_file('sha256', $dir.'/register.mlb'), '--purpose' => 'erasure-register'])->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
