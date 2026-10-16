<?php

namespace Tests\Feature;

use App\Domains\Identity\Actions\Invitations;
use App\Domains\Identity\Delivery\FakeTransport;
use App\Domains\Membership\Actions\CsvImport;
use App\Domains\Membership\Actions\LodgeSettings;
use App\Domains\Membership\Actions\Members;
use App\Domains\Membership\Models\Enrollment;
use App\Domains\Membership\Models\ImportBatch;
use App\Domains\Membership\Models\MemberHistory;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Models\User;
use Database\Seeders\MembershipDemoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private Lodge $a;

    private Lodge $b;

    private User $admin;

    private Members $members;

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity.delivery_driver' => 'fake']);
        $owner = Obedience::query()->create(['code' => 'MEM', 'name' => 'Obbedienza Fittizia', 'is_active' => true]);
        $rite = Rite::query()->create(['code' => 'MEM', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $owner->rites()->attach($rite);
        $this->a = Lodge::query()->create(['obedience_id' => $owner->id, 'rite_id' => $rite->id, 'number' => '1', 'name' => 'Loggia Fittizia Alfa', 'is_active' => true]);
        $this->b = Lodge::query()->create(['obedience_id' => $owner->id, 'rite_id' => $rite->id, 'number' => '2', 'name' => 'Loggia Fittizia Beta', 'is_active' => true]);
        $this->admin = User::factory()->create();
        $this->role($this->admin, $this->a, 'administrator');
        $this->role($this->admin, $this->b, 'administrator');
        $this->select($this->admin, $this->a);
        $this->members = app(Members::class);
    }

    public function test_membership_status_labels_and_optional_expulsion_date(): void
    {
        $this->assertSame(['active' => 'Attivo', 'suspended' => 'In Sonno', 'ended' => 'Depennato'], __('membership.status'));
        foreach (['active', 'suspended'] as $status) {
            $v = $this->members->enrollmentValues(array_replace($this->values(), ['status' => $status, 'ends_on' => '2026-01-02']));
            $this->assertNull($v['ends_on']);
        }
        $v = $this->members->enrollmentValues(array_replace($this->values(), ['status' => 'ended', 'ends_on' => null]));
        $this->assertSame(now()->toDateString(), $v['ends_on']);
        $this->get(route('members.create', $this->a->id))->assertOk()->assertSee('Data Depennamento (facoltativa)')->assertSee('In Sonno')->assertSee('Depennato')->assertSee('x-show', false);
    }

    private function role(User $user, Lodge $lodge, string $role): void
    {
        DB::table('lodge_user')->updateOrInsert(['lodge_id' => $lodge->id, 'user_id' => $user->id], ['is_active' => true]);
        DB::table('lodge_user_roles')->insertOrIgnore(['lodge_id' => $lodge->id, 'user_id' => $user->id, 'role' => $role]);
    }

    private function select(User $user, Lodge $lodge): void
    {
        $this->useLocalLodge($user, $lodge->id);
        $this->actingAs($user);
        session()->put(['active_lodge_id' => $lodge->id, 'auth.second_factor_user_id' => $user->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $user->session_version]);
    }

    /** @return array<string,mixed> */
    private function values(string $reference = 'FICTION_001'): array
    {
        return ['reference' => $reference, 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio', 'email' => 'fittizio@example.invalid', 'phone' => '+390000000001', 'address' => 'Indirizzo Fittizio', 'birth_date' => '1990-01-01', 'starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active', 'administrative_exemption' => false, 'exemption_reason' => null, 'confirmation' => '1'];
    }

    private function person(string $reference = 'FICTION_001'): Person
    {
        return $this->members->create($this->a->id, $this->values($reference));
    }

    private function foreign(): Person
    {
        $this->select($this->admin, $this->b);
        $person = $this->members->create($this->b->id, $this->values('FOREIGN_ONLY'));
        $this->select($this->admin, $this->a);

        return $person;
    }

    private function csv(string $reference = 'CSV_001'): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, CsvImport::HEADERS, ',', '"', '');
        $values = $this->values($reference);
        fputcsv($stream, array_map(fn ($key) => $key === 'administrative_exemption' ? '0' : ($values[$key] ?? ''), CsvImport::HEADERS), ',', '"', '');
        rewind($stream);
        $result = stream_get_contents($stream);
        fclose($stream);

        return $result;
    }

    public function test_person_enrollment_account_and_roles_are_distinct(): void
    {
        $person = $this->person();
        $this->assertNull($person->user_id);
        $this->assertCount(1, $person->enrollments);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('member_history', 1);
        $raw = DB::table('member_people')->where('id', $person->id)->first();
        $this->assertNotSame($person->phone, $raw->phone);
        $this->assertNotSame($person->address, $raw->address);
        $this->assertStringNotContainsString('NomeFittizio', DB::table('member_history')->value('snapshot'));
    }

    public function test_cross_tenant_url_ids_cannot_read_or_write_people(): void
    {
        $foreign = $this->foreign();
        $this->get(route('members.show', [$this->a->id, $foreign->id]))->assertNotFound();
        $this->post(route('members.update', [$this->a->id, $foreign->id]), $this->values() + ['version' => 1])->assertNotFound();
        $this->get(route('members.show', [$this->b->id, $foreign->id]))->assertStatus(409);
        $this->post(route('members.store', $this->b->id), $this->values())->assertStatus(409);
        $this->assertSame('FOREIGN_ONLY', $foreign->fresh()->reference);
    }

    public function test_local_admin_without_foreign_role_has_no_access(): void
    {
        $foreign = $this->foreign();
        $admin = User::factory()->create();
        $this->role($admin, $this->a, 'administrator');
        $this->select($admin, $this->a);
        $this->get(route('members.show', [$this->a->id, $foreign->id]))->assertNotFound();
        $this->get(route('members.show', [$this->b->id, $foreign->id]))->assertStatus(409);
        session()->put('active_lodge_id', $this->b->id);
        $this->get(route('members.index'))->assertStatus(409);
    }

    public function test_super_user_has_no_implicit_directory_access(): void
    {
        $this->person();
        $super = User::factory()->create(['is_platform_admin' => true]);
        $this->select($super, $this->a);
        $this->get(route('members.index'))->assertStatus(409);
        $this->get(route('members.settings'))->assertStatus(409);
    }

    public function test_brother_sees_only_own_person_in_selected_lodge(): void
    {
        $person = $this->person();
        $other = $this->person('OTHER_001');
        $foreign = $this->foreign();
        $user = User::factory()->create();
        $person->forceFill(['user_id' => $user->id])->save();
        $foreign->forceFill(['user_id' => $user->id])->save();
        $this->role($user, $this->a, 'brother');
        $this->select($user, $this->a);
        $this->get(route('members.position'))->assertOk()->assertSee('FICTION_001')->assertDontSee('OTHER_001')->assertDontSee('FOREIGN_ONLY');
        $this->get(route('members.index'))->assertForbidden();
        $this->get(route('members.show', [$this->a->id, $other->id]))->assertForbidden();
        $this->get(route('members.export'))->assertForbidden();
        DB::table('lodge_user')->where('user_id', $user->id)->where('lodge_id', $this->a->id)->update(['is_active' => false]);
        $this->role($user, $this->b, 'brother');
        $this->select($user, $this->b);
        $this->get(route('members.position'))->assertOk()->assertSee('FOREIGN_ONLY')->assertDontSee('FICTION_001');
        $this->expectException(ModelNotFoundException::class);
        $this->members->ownEnrollment($this->b->id, $person->enrollments()->first()->id);
    }

    public function test_treasurer_reads_and_exports_but_cannot_modify_or_import(): void
    {
        $person = $this->person();
        $user = User::factory()->create();
        $this->role($user, $this->a, 'treasurer');
        $this->select($user, $this->a);
        $this->get(route('members.index'))->assertOk();
        $this->get(route('members.show', [$this->a->id, $person->id]))->assertOk();
        $this->get(route('members.export'))->assertOk();
        $this->post(route('members.update', [$this->a->id, $person->id]), $this->values() + ['version' => 1])->assertForbidden();
        $this->get(route('members.import'))->assertForbidden();
        $this->get(route('members.settings'))->assertForbidden();
    }

    public function test_directory_search_and_latest_status_are_scoped(): void
    {
        $person = $this->person();
        $this->foreign();
        $period = $person->enrollments()->first();
        $this->members->deactivate($this->a->id, $person->id, $period->id, 1, '2026-03-01');
        $this->members->enrollment($this->a->id, $person->id, array_replace($this->values(), ['starts_on' => '2026-03-02']));
        $this->get(route('members.index', ['search' => 'CognomeFittizio']))->assertOk()->assertSee('FICTION_001')->assertDontSee('FOREIGN_ONLY');
        $this->get(route('members.index', ['status' => 'ended']))->assertOk()->assertDontSee('FICTION_001');
        $this->get(route('members.index', ['status' => 'active']))->assertOk()->assertSee('FICTION_001');
    }

    public function test_deactivation_keeps_history_and_prevents_reopening(): void
    {
        $person = $this->person();
        $period = $person->enrollments()->first();
        $this->post(route('members.deactivate', [$this->a->id, $person->id, $period->id]), ['version' => 1, 'ends_on' => '2026-03-01', 'confirmation' => 1])->assertRedirect();
        $this->assertSame('ended', $period->fresh()->status);
        $this->assertDatabaseCount('member_history', 2);
        $this->post(route('members.enrollment', [$this->a->id, $person->id]), $this->values() + ['enrollment_id' => $period->id, 'version' => 2])->assertStatus(409);
        $this->assertDatabaseHas('member_people', ['id' => $person->id]);
        $this->expectException(\LogicException::class);
        $period->fresh()->delete();
    }

    public function test_overlaps_and_unmotivated_exemptions_are_rejected(): void
    {
        $person = $this->person();
        $this->post(route('members.enrollment', [$this->a->id, $person->id]), array_replace($this->values(), ['starts_on' => '2026-02-01']))->assertSessionHasErrors('starts_on');
        $this->post(route('members.store', $this->a->id), array_replace($this->values('EXEMPT'), ['administrative_exemption' => 1]))->assertSessionHasErrors('exemption_reason');
        $this->assertDatabaseCount('member_people', 1);
    }

    public function test_confirmation_and_optimistic_version_are_required(): void
    {
        $person = $this->person();
        $data = $this->values();
        unset($data['confirmation']);
        $this->post(route('members.store', $this->a->id), $data)->assertSessionHasErrors('confirmation');
        $this->post(route('members.update', [$this->a->id, $person->id]), $this->values() + ['version' => 0])->assertStatus(409);
        $this->post(route('members.update', [$this->a->id, $person->id]), array_replace($this->values(), ['version' => 1, 'first_name' => 'CorrezioneFittizia', 'user_id' => $this->admin->id, 'lodge_id' => $this->b->id]))->assertRedirect();
        $this->assertNull($person->fresh()->user_id);
        $this->assertSame($this->a->id, $person->fresh()->lodge_id);
        $snapshot = MemberHistory::query()->orderBy('id')->first()->snapshot;
        $this->assertSame('NomeFittizio', $snapshot['person']['first_name']);
        $this->assertSame('2026-01-01', $snapshot['enrollments'][0]['starts_on']);
    }

    public function test_database_foreign_key_prevents_cross_tenant_enrollment(): void
    {
        $person = $this->person();
        $this->expectException(QueryException::class);
        Enrollment::query()->forceCreate(['lodge_id' => $this->b->id, 'person_id' => $person->id, 'starts_on' => '2026-01-01', 'status' => 'active']);
    }

    public function test_reference_is_local_and_account_cannot_link_two_local_people(): void
    {
        $person = $this->person();
        $this->select($this->admin, $this->b);
        $otherLodge = $this->members->create($this->b->id, $this->values());
        $this->assertSame($person->reference, $otherLodge->reference);
        $this->select($this->admin, $this->a);
        $second = $this->person('SECOND_001');
        $person->forceFill(['user_id' => $this->admin->id])->save();
        $this->expectException(QueryException::class);
        $second->forceFill(['user_id' => $this->admin->id])->save();
    }

    public function test_csv_preview_is_encrypted_and_confirmation_is_single_use(): void
    {
        $import = app(CsvImport::class);
        $preview = $import->preview($this->a->id, $this->csv());
        $this->assertDatabaseCount('member_people', 0);
        $this->assertSame([], $preview['batch']->payload['errors']);
        $this->assertStringNotContainsString('NomeFittizio', DB::table('member_imports')->value('payload'));
        $this->assertSame(1, $import->confirm($this->a->id, $preview['token']));
        $this->assertSame([], $preview['batch']->fresh()->payload['rows']);
        $this->assertDatabaseCount('member_people', 1);
        $this->expectException(ModelNotFoundException::class);
        $import->confirm($this->a->id, $preview['token']);
    }

    public function test_csv_bad_rows_and_duplicates_block_whole_batch(): void
    {
        $import = app(CsvImport::class);
        $csv = $this->csv();
        $csv .= substr($csv, strpos($csv, "\n") + 1);
        $preview = $import->preview($this->a->id, $csv);
        $this->assertCount(1, $preview['batch']->payload['errors']);
        try {
            $import->confirm($this->a->id, $preview['token']);
            $this->fail('Invalid batch accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('member_people', 0);
        }
    }

    public function test_csv_revalidates_and_rolls_back_on_concurrent_duplicate(): void
    {
        $import = app(CsvImport::class);
        $csv = $this->csv('CSV_FIRST');
        $second = $this->csv('CSV_SECOND');
        $csv .= substr($second, strpos($second, "\n") + 1);
        $preview = $import->preview($this->a->id, $csv);
        $this->person('CSV_SECOND');
        try {
            $import->confirm($this->a->id, $preview['token']);
            $this->fail('Duplicate accepted');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('member_people', ['reference' => 'CSV_FIRST']);
            $this->assertNull($preview['batch']->fresh()->consumed_at);
        }
    }

    public function test_csv_preview_is_bound_to_actor_session_and_tenant(): void
    {
        $preview = app(CsvImport::class)->preview($this->a->id, $this->csv());
        $this->get(route('members.import.preview', [$this->b->id, $preview['token']]))->assertStatus(409);
        $user = User::factory()->create();
        $this->role($user, $this->a, 'administrator');
        $this->select($user, $this->a);
        $this->get(route('members.import.preview', [$this->a->id, $preview['token']]))->assertNotFound();
        $this->select($this->admin, $this->a);
        session()->forget('member.import_nonce');
        $this->get(route('members.import.preview', [$this->a->id, $preview['token']]))->assertNotFound();
    }

    public function test_csv_expiry_and_limits_fail_closed(): void
    {
        $preview = app(CsvImport::class)->preview($this->a->id, $this->csv());
        $preview['batch']->expires_at = now('UTC');
        $preview['batch']->save();
        $this->get(route('members.import.preview', [$this->a->id, $preview['token']]))->assertNotFound();
        foreach (['invalid header', str_repeat('x', 262145), "\0bad"] as $csv) {
            try {
                app(CsvImport::class)->preview($this->a->id, $csv);
                $this->fail('Bad CSV accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('member_people', 0);
            }
        }
    }

    public function test_csv_http_preview_and_explicit_confirmation(): void
    {
        $response = $this->post(route('members.import.upload', $this->a->id), ['csv' => UploadedFile::fake()->createWithContent('iscritti.csv', $this->csv())]);
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->get($url)->assertOk()->assertSee('CSV_001')->assertSee('Conferma importazione');
        $token = basename($url);
        $this->post(route('members.import.confirm', [$this->a->id, $token]))->assertSessionHasErrors('confirmation');
        $this->post(route('members.import.confirm', [$this->a->id, $token]), ['confirmation' => 1])->assertRedirect(route('members.index'));
        $this->assertDatabaseCount('member_people', 1);
    }

    public function test_export_is_scoped_minimized_and_formula_safe(): void
    {
        $this->members->create($this->a->id, array_replace($this->values(), ['first_name' => '=HYPERLINK(1)', 'last_name' => 'FittizioAlfa']));
        $this->foreign();
        $csv = $this->get(route('members.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK(1)", $csv);
        $this->assertStringContainsString('FittizioAlfa', $csv);
        $this->assertStringNotContainsString('FOREIGN_ONLY', $csv);
        $this->assertStringNotContainsString('fittizio@example.invalid', $csv);
        $this->assertStringNotContainsString('+390000000001', $csv);
    }

    public function test_member_invitation_links_new_account_only_after_acceptance(): void
    {
        $person = $this->person();
        $invite = app(Invitations::class)->lodge($this->a->id, $person->email, ['brother'], personId: $person->id);
        $this->assertSame($person->id, $invite->person_id);
        $this->assertNull($person->fresh()->user_id);
        $message = end(app(FakeTransport::class)->messages);
        preg_match('~/inviti/([a-f0-9]+)~', $message['body'], $matches);
        auth()->logout();
        $user = app(Invitations::class)->accept($matches[1], ['name' => 'Account Fittizio', 'password' => 'PasswordFittizia!2026', 'password_confirmation' => 'PasswordFittizia!2026']);
        $this->assertGuest();
        $this->assertSame($user->id, $person->fresh()->user_id);
        $this->assertDatabaseHas('lodge_user_roles', ['lodge_id' => $this->a->id, 'user_id' => $user->id, 'role' => 'brother']);
    }

    public function test_same_verified_brother_cannot_accept_a_second_lodge_invite(): void
    {
        $a = $this->person();
        $b = $this->foreign();
        $secondAdmin = User::factory()->create();
        $this->role($secondAdmin, $this->b, 'administrator');
        $tokens = [];
        foreach ([$a, $b] as $person) {
            $lodge = $person->lodge_id === $this->a->id ? $this->a : $this->b;
            $this->select($lodge->id === $this->a->id ? $this->admin : $secondAdmin, $lodge);
            app(Invitations::class)->lodge($lodge->id, $person->email, ['brother'], personId: $person->id);
            $message = end(app(FakeTransport::class)->messages);
            preg_match('~/inviti/([a-f0-9]+)~', $message['body'], $matches);
            $tokens[] = $matches[1];
        }
        $user = User::factory()->create(['email' => 'fittizio@example.invalid']);
        $this->select($user, $this->a);
        app(Invitations::class)->accept($tokens[0], []);
        try {
            app(Invitations::class)->accept($tokens[1], []);
            $this->fail('Seconda appartenenza consentita.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('roles', $error->errors());
        }
        $this->assertSame($user->id, $a->fresh()->user_id);
        $this->assertNull($b->fresh()->user_id);
        $this->assertSame(1, DB::table('lodge_user_roles')->where('user_id', $user->id)->count());
    }

    public function test_changed_email_or_closed_enrollment_invalidates_pending_invite(): void
    {
        $person = $this->person();
        $invite = app(Invitations::class)->lodge($this->a->id, $person->email, ['brother'], personId: $person->id);
        $this->members->update($this->a->id, $person->id, array_replace($this->values(), ['version' => 1, 'email' => 'nuovo@example.invalid']));
        $this->assertNotNull($invite->fresh()->revoked_at);
        $person->refresh();
        $invite = app(Invitations::class)->lodge($this->a->id, $person->email, ['brother'], personId: $person->id);
        $period = $person->enrollments()->first();
        $this->members->deactivate($this->a->id, $person->id, $period->id, 1, '2026-03-01');
        $this->assertNotNull($invite->fresh()->revoked_at);
    }

    public function test_settings_cannot_change_organization_or_grant_permissions(): void
    {
        $lodge = $this->a->fresh();
        $values = $lodge->only(['name', 'email', 'phone', 'address', 'administrative_email', 'reminder_days', 'brothers_treasury_visible']);
        $values['revision'] = app(LodgeSettings::class)->revision($lodge);
        $values['name'] = 'Nome Loggia Fittizio Nuovo';
        $values['brothers_treasury_visible'] = true;
        app(LodgeSettings::class)->save($lodge->id, $values + ['obedience_id' => 999, 'rite_id' => 999, 'is_active' => false, 'roles' => ['administrator']]);
        $this->assertSame($lodge->obedience_id, $lodge->fresh()->obedience_id);
        $this->assertSame($lodge->rite_id, $lodge->fresh()->rite_id);
        $this->assertTrue($lodge->fresh()->is_active);
        $this->assertTrue($lodge->fresh()->brothers_treasury_visible);
        $this->get(route('members.settings'))->assertOk()->assertSee('Nome Loggia Fittizio Nuovo')->assertDontSee('Loggia Fittizia Beta');
        $this->expectException(HttpException::class);
        app(LodgeSettings::class)->save($lodge->id, $values);
    }

    public function test_revoked_access_and_missing_otp_protect_all_module_endpoints(): void
    {
        $this->person();
        session()->forget('auth.second_factor_user_id');
        $this->get(route('members.index'))->assertForbidden();
        $this->select($this->admin, $this->a);
        DB::table('lodge_user')->where('lodge_id', $this->a->id)->where('user_id', $this->admin->id)->update(['is_active' => false]);
        $this->get(route('members.index'))->assertStatus(409);
        $this->post(route('members.store', $this->a->id), $this->values())->assertStatus(409);
    }

    public function test_demo_seed_is_fictitious_idempotent_and_creates_no_accounts(): void
    {
        $before = User::query()->count();
        $this->seed(MembershipDemoSeeder::class);
        $this->assertDatabaseCount('member_people', 9);
        $this->seed(MembershipDemoSeeder::class);
        $this->assertDatabaseCount('member_people', 9);
        $this->assertDatabaseCount('member_enrollments', 9);
        $this->assertSame($before, User::query()->count());
        $this->assertSame(0, Person::query()->whereNotNull('user_id')->count());
    }

    public function test_demo_seed_is_refused_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->expectException(\LogicException::class);
        (new MembershipDemoSeeder)->run();
    }

    public function test_import_temporary_data_is_pruned_after_expiry(): void
    {
        $batch = app(CsvImport::class)->preview($this->a->id, $this->csv())['batch'];
        $batch->expires_at = now('UTC');
        $batch->save();
        $this->artisan('model:prune', ['--model' => [ImportBatch::class]])->assertSuccessful()->run();
        $this->assertDatabaseCount('member_imports', 0);
    }

    public function test_csv_rejects_more_than_200_rows_and_accepts_quoted_newlines(): void
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, CsvImport::HEADERS, ',', '"', '');
        for ($i = 0; $i < 201; $i++) {
            $row = $this->values('ROW_'.$i);
            fputcsv($stream, array_map(fn ($k) => $k === 'administrative_exemption' ? '0' : ($row[$k] ?? ''), CsvImport::HEADERS), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        try {
            app(CsvImport::class)->preview($this->a->id, $csv);
            $this->fail('Limit missing');
        } catch (ValidationException) {
            $this->assertDatabaseCount('member_imports', 0);
        }
        $csv = str_replace('Indirizzo Fittizio', "Indirizzo, Fittizio\nSeconda riga", $this->csv());
        // Re-encode the multiline field rather than injecting a broken CSV record.
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, CsvImport::HEADERS, ',', '"', '');
        $row = array_replace($this->values('MULTILINE'), ['address' => "Indirizzo, Fittizio\nSeconda riga"]);
        fputcsv($stream, array_map(fn ($k) => $k === 'administrative_exemption' ? '0' : ($row[$k] ?? ''), CsvImport::HEADERS), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $preview = app(CsvImport::class)->preview($this->a->id, $csv);
        $this->assertSame([], $preview['batch']->payload['errors']);
        $this->assertSame(1, app(CsvImport::class)->confirm($this->a->id, $preview['token']));
        $this->assertSame("Indirizzo, Fittizio\nSeconda riga", Person::query()->first()->address);
    }

    public function test_history_and_person_cannot_be_deleted_or_rewritten(): void
    {
        $person = $this->person();
        $history = $person->history()->first();
        try {
            $person->delete();
            $this->fail('Person deleted');
        } catch (\LogicException) {
            $this->assertDatabaseCount('member_people', 1);
        }
        $history->event = 'forged';
        $this->expectException(\LogicException::class);
        $history->save();
    }

    public function test_privacy_headers_and_escaped_person_names(): void
    {
        $this->members->create($this->a->id, array_replace($this->values(), ['first_name' => '<script>alert(1)</script>']));
        $this->get(route('members.index'))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_foreign_enrollment_and_invitation_identifiers_are_rejected(): void
    {
        $person = $this->person();
        $foreign = $this->foreign();
        $period = $foreign->enrollments()->first();
        $this->post(route('members.enrollment', [$this->a->id, $person->id]), $this->values() + ['enrollment_id' => $period->id, 'version' => 1])->assertNotFound();
        $this->post(route('members.deactivate', [$this->a->id, $person->id, $period->id]), ['confirmation' => 1, 'version' => 1, 'ends_on' => '2026-03-01'])->assertNotFound();
        $this->post(route('members.invite', [$this->a->id, $foreign->id]), ['confirmation' => 1])->assertNotFound();
        $this->assertDatabaseCount('identity_invitations', 0);
        $this->assertSame('active', $period->fresh()->status);
    }
}
