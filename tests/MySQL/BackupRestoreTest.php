<?php

namespace Tests\MySQL;

use App\Domains\Capitations\Actions\Generator as CapGenerator;
use App\Domains\Capitations\Actions\Payments as Capit;
use App\Domains\Capitations\Actions\Plans;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Levies\Actions\Generator as LevyGenerator;
use App\Domains\Levies\Actions\Payments as Levies;
use App\Domains\Levies\Actions\Rules;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Membership\Actions\Members;
use App\Domains\Membership\Models\Person;
use App\Domains\Operations\Backup;
use App\Domains\Operations\EncryptedArchive;
use App\Domains\Operations\Integrity;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use App\Domains\Reports\Builder;
use App\Domains\Treasury\Actions\Attachments;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Operation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use DatabaseMigrations;

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

    public function test_mysql_backup_restores_finances_private_attachments_and_quarantines_old_runtime(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->fixture();
        $before = app(Builder::class)->make($this->a->id, 'financial', ['from' => '2026-10-01', 'to' => '2026-10-15']);
        $this->assertSame([], app(Integrity::class)->check()['issues']);
        $oldStorage = storage_path();
        $oldDisk = config('filesystems.disks.local.root');
        $dir = sys_get_temp_dir().'/restore-drill-'.bin2hex(random_bytes(10));
        mkdir($dir, 0700);
        mkdir($dir.'/storage/framework', 0700, true);
        mkdir($dir.'/storage/app/private', 0700, true);
        app()->useStoragePath($dir.'/storage');
        config(['filesystems.disks.local.root' => storage_path('app/private'), 'operations.backup_directory' => $dir.'/backups']);
        Storage::forgetDisk('local');
        $pair = sodium_crypto_box_keypair();
        file_put_contents($dir.'/public.key', base64_encode(sodium_crypto_box_publickey($pair)));
        chmod($dir.'/public.key', 0600);
        config(['operations.backup_public_key' => $dir.'/public.key']);
        $archive = null;
        try {
            $op = Operation::query()->where('lodge_id', $this->a->id)->where('kind', 'income')->firstOrFail();
            $bytes = "%PDF-1.4\nDocumento interamente Fittizio\n";
            $attachment = app(Attachments::class)->upload($this->a->id, $op->id, UploadedFile::fake()->createWithContent('documento-fittizio.pdf', $bytes));
            $this->artisan('down')->assertSuccessful();
            $backup = app(Backup::class);
            $name = $backup->run();
            $this->assertSame($name, $backup->run());
            $this->assertDatabaseCount('backup_runs', 1);
            $archive = $dir.'/backups/'.$name;
            $this->assertFileExists($archive);
            $this->assertSame(0600, fileperms($archive) & 0777);
            $this->assertStringNotContainsString('CREATE TABLE', file_get_contents($archive));
            $manifest = EncryptedArchive::extract($archive, sodium_crypto_box_secretkey($pair), $dir.'/restored');
            $this->assertArrayHasKey('private/'.$attachment->path, $manifest);
            $this->assertStringNotContainsString('CREATE DATABASE', file_get_contents($dir.'/restored/database.sql'));
            $keys = json_decode(file_get_contents($dir.'/restored/application-keys.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(config('app.key'), $keys['APP_KEY']);
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            $this->assertDatabaseCount('treasury_operations', 0);
            $settings = DB::connection()->getConfig();
            $quote = fn ($v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v).'"';
            $defaults = $dir.'/mysql-client.cnf';
            $ini = "[client]\n";
            foreach (['host' => 'host', 'port' => 'port', 'user' => 'username', 'password' => 'password'] as $option => $field) {
                $ini .= $option.'='.$quote($settings[$field])."\n";
            }file_put_contents($defaults, $ini);
            chmod($defaults, 0600);
            $client = getenv('MYSQL_BINARY') ?: 'mysql';
            $input = fopen($dir.'/restored/database.sql', 'rb');
            $process = new Process([$client, '--defaults-extra-file='.$defaults, '--binary-mode=1', '--local-infile=0', '--database='.$settings['database']]);
            $process->setTimeout(60);
            $process->setInput($input);
            $process->run();
            fclose($input);
            $this->assertTrue($process->isSuccessful(), 'Importazione nel database di test non riuscita.');
            unlink($defaults);
            File::deleteDirectory(storage_path('app/private'));
            File::copyDirectory($dir.'/restored/private', storage_path('app/private'));
            $after = app(Builder::class)->make($this->a->id, 'financial', ['from' => '2026-10-01', 'to' => '2026-10-15']);
            $this->assertSame($before->metrics, $after->metrics);
            $this->assertSame($before->rows, $after->rows);
            $this->assertSame([], app(Integrity::class)->check()['issues']);
            $this->assertSame($bytes, app(Attachments::class)->download($this->a->id, $attachment->id)['bytes']);
            $this->assertSame(['checked' => 1, 'issues' => []], app(Integrity::class)->files());
            $count = DB::table('treasury_operations')->count();
            $version = $this->user->fresh()->session_version;
            $this->artisan('operations:quarantine-restored', ['--confirm-restore' => true])->assertSuccessful();
            $this->assertSame($version + 1, $this->user->fresh()->session_version);
            $this->assertFalse($this->user->fresh()->is_active);
            $this->assertSame($count, DB::table('treasury_operations')->count());
            $this->assertSame([], app(Integrity::class)->check()['issues']);
        } finally {
            $this->artisan('up');
            app()->useStoragePath($oldStorage);
            config(['filesystems.disks.local.root' => $oldDisk]);
            Storage::forgetDisk('local');
            File::deleteDirectory($dir);
        }
    }
}
