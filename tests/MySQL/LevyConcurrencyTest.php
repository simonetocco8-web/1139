<?php

namespace Tests\MySQL;

use App\Domains\Levies\Actions\Balances;
use App\Domains\Levies\Actions\Generator;
use App\Domains\Levies\Models\Condition;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Levies\Models\Program;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LevyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_generators_create_only_one_obligation_per_cycle(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $o = Obedience::query()->create(['code' => 'CONLEV', 'name' => 'Obbedienza Concorrenza Fittizia', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'CONLEV', 'name' => 'Rito Concorrenza Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $l = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Concorrenza Fittizia', 'is_active' => true]);
        $structure = Structure::query()->create(['obedience_id' => $o->id, 'name' => 'Nazionale Concorrenza Fittizia', 'level' => 'national', 'is_active' => true]);
        TerritorialAssignment::query()->create(['lodge_id' => $l->id, 'obedience_id' => $o->id, 'structure_id' => $structure->id, 'level' => 'national', 'structure_name' => $structure->name, 'starts_at' => '2025-01-01 00:00:00']);
        $program = Program::query()->create(['obedience_id' => $o->id, 'code' => 'CONLEV', 'description' => 'Canone Concorrenza Fittizio', 'level' => 'national', 'periodicity' => 'monthly', 'starts_on' => '2026-01-01', 'created_at' => now('UTC')]);
        Condition::query()->create(['obedience_id' => $o->id, 'program_id' => $program->id, 'scope_key' => 'obedience', 'effective_on' => '2026-01-01', 'basis' => 'fixed', 'amount' => '100.00', 'count_criterion' => 'active_at_start', 'due_basis' => 'start', 'due_days' => 0, 'is_active' => true, 'reason' => 'Condizione Fittizia', 'created_at' => now('UTC')]);
        $dir = sys_get_temp_dir().'/levy-concurrency-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $w = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/levy-worker.php'), (string) $l->id, '2026-01-01', $dir.'/ready'.$i, $dir.'/start'], base_path(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
                $w->setTimeout(20);
                $w->start();
                $workers[] = $w;
            }
            $until = microtime(true) + 10;
            while ((! is_file($dir.'/ready0') || ! is_file($dir.'/ready1')) && microtime(true) < $until) {
                usleep(10000);
            }$this->assertFileExists($dir.'/ready0');
            $this->assertFileExists($dir.'/ready1');
            touch($dir.'/start');
            $counts = [];
            foreach ($workers as $w) {
                $w->wait();
                $this->assertTrue($w->isSuccessful(), $w->getErrorOutput());
                $this->assertJson($w->getOutput(), $w->getOutput().$w->getErrorOutput());
                $counts[] = json_decode($w->getOutput(), true, 512, JSON_THROW_ON_ERROR)['created'];
            }
            sort($counts);
            $this->assertSame([0, 1], $counts);
            $this->assertDatabaseCount('levy_obligations', 1);
            $this->assertDatabaseCount('levy_payments', 0);
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }

    public function test_concurrent_payment_retries_post_only_one_outgoing(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $o = Obedience::query()->create(['code' => 'CONLEV', 'name' => 'Obbedienza Concorrenza Fittizia', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'CONLEV', 'name' => 'Rito Concorrenza Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $l = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Concorrenza Fittizia', 'is_active' => true]);
        $structure = Structure::query()->create(['obedience_id' => $o->id, 'name' => 'Nazionale Concorrenza Fittizia', 'level' => 'national', 'is_active' => true]);
        TerritorialAssignment::query()->create(['lodge_id' => $l->id, 'obedience_id' => $o->id, 'structure_id' => $structure->id, 'level' => 'national', 'structure_name' => $structure->name, 'starts_at' => '2025-01-01 00:00:00']);
        $program = Program::query()->create(['obedience_id' => $o->id, 'code' => 'CONLEV', 'description' => 'Canone Concorrenza Fittizio', 'level' => 'national', 'periodicity' => 'monthly', 'starts_on' => '2026-01-01', 'created_at' => now('UTC')]);
        Condition::query()->create(['obedience_id' => $o->id, 'program_id' => $program->id, 'scope_key' => 'obedience', 'effective_on' => '2026-01-01', 'basis' => 'fixed', 'amount' => '100.00', 'count_criterion' => 'active_at_start', 'due_basis' => 'start', 'due_days' => 0, 'is_active' => true, 'reason' => 'Condizione Fittizia', 'created_at' => now('UTC')]);
        app(Generator::class)->run($l->id, '2026-01-01');
        $obligation = Obligation::query()->firstOrFail();
        $user = User::factory()->create();
        DB::table('lodge_user')->insert(['lodge_id' => $l->id, 'user_id' => $user->id, 'is_active' => true]);
        DB::table('lodge_user_roles')->insert(['lodge_id' => $l->id, 'user_id' => $user->id, 'role' => 'treasurer']);
        $key = bin2hex(random_bytes(24));
        $dir = sys_get_temp_dir().'/levy-concurrency-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $w = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/levy-payment-worker.php'), (string) $l->id, '2026-01-01', $dir.'/ready'.$i, $dir.'/start', (string) $user->id, (string) $obligation->id, $key], base_path(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
                $w->setTimeout(20);
                $w->start();
                $workers[] = $w;
            }
            $until = microtime(true) + 10;
            while ((! is_file($dir.'/ready0') || ! is_file($dir.'/ready1')) && microtime(true) < $until) {
                usleep(10000);
            }$this->assertFileExists($dir.'/ready0');
            $this->assertFileExists($dir.'/ready1');
            touch($dir.'/start');
            $counts = [];
            foreach ($workers as $w) {
                $w->wait();
                $this->assertTrue($w->isSuccessful(), $w->getErrorOutput());
                $this->assertJson($w->getOutput(), $w->getOutput().$w->getErrorOutput());
                $counts[] = json_decode($w->getOutput(), true, 512, JSON_THROW_ON_ERROR)['payment'];
            }
            sort($counts);
            $this->assertSame($counts[0], $counts[1]);
            $this->assertSame(1, DB::table('identity_events')->where('event', 'test.treasury.disbursed')->count());
            $this->assertSame(4000, Balances::of($obligation->fresh())['residual']);
            $this->assertDatabaseCount('levy_obligations', 1);
            $this->assertDatabaseCount('levy_payments', 1);
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }
}
