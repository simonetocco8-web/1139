<?php

namespace Tests\MySQL;

use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Models\PlanVersion;
use App\Domains\Membership\Models\Enrollment;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CapitationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_generators_create_only_one_rate_per_cycle(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $o = Obedience::query()->create(['code' => 'CONCAP', 'name' => 'Obbedienza Concorrenza Fittizia', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'CONCAP', 'name' => 'Rito Concorrenza Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $l = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Concorrenza Fittizia', 'is_active' => true]);
        $p = Person::query()->forceCreate(['lodge_id' => $l->id, 'reference' => 'CONCAP', 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio']);
        Enrollment::query()->forceCreate(['lodge_id' => $l->id, 'person_id' => $p->id, 'starts_on' => '2026-01-01', 'status' => 'active']);
        $plan = Plan::query()->create(['lodge_id' => $l->id, 'description' => 'Piano Fittizio', 'periodicity' => 'monthly', 'starts_on' => '2026-01-01']);
        PlanVersion::query()->create(['lodge_id' => $l->id, 'plan_id' => $plan->id, 'effective_on' => '2026-01-01', 'description' => 'Piano Fittizio', 'amount' => '100.00', 'due_basis' => 'start', 'due_days' => 0, 'prorata' => 'none', 'is_active' => true, 'created_at' => now('UTC')]);
        $dir = sys_get_temp_dir().'/capitation-concurrency-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $w = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/capitation-worker.php'), (string) $l->id, '2026-01-01', $dir.'/ready'.$i, $dir.'/start'], base_path(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
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
                $counts[] = json_decode($w->getOutput(), true, 512, JSON_THROW_ON_ERROR)['created'];
            }
            sort($counts);
            $this->assertSame([0, 1], $counts);
            $this->assertDatabaseCount('capitation_installments', 1);
            $this->assertDatabaseCount('capitation_receipts', 0);
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
