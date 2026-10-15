<?php

namespace Tests\MySQL;

use App\Domains\Notifications\Outbox;
use App\Domains\Notifications\Producer;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CommunicationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_workers_claim_one_external_send_and_one_attempt(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        config(['communications.enabled' => true]);
        $o = Obedience::query()->create(['code' => 'NOTICE', 'name' => 'Obbedienza Fittizia', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'NOTICE', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $l = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Fittizia', 'is_active' => true]);
        $u = User::factory()->create();
        DB::table('lodge_user')->insert(['lodge_id' => $l->id, 'user_id' => $u->id, 'is_active' => true]);
        DB::table('lodge_user_roles')->insert(['lodge_id' => $l->id, 'user_id' => $u->id, 'role' => 'administrator']);
        DB::table('communication_rules')->insert(['lodge_id' => $l->id, 'type' => 'administrative', 'enabled' => true]);
        $this->actingAs($u);
        session()->put(['active_lodge_id' => $l->id, 'auth.second_factor_user_id' => $u->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $u->session_version]);
        app(Producer::class)->administrative($l->id, 'Comunicazione Fittizia concorrente', bin2hex(random_bytes(32)));
        $row = Outbox::query()->sole();
        $dir = sys_get_temp_dir().'/notice-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ([1, 2] as $n) {
                $worker = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/communication-worker.php'), (string) $row->id, $dir.'/ready'.$n, $dir.'/start', $dir.'/sent'], base_path(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
                $worker->setTimeout(25);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($dir.'/ready1') || ! is_file($dir.'/ready2')) && microtime(true) < $deadline) {
                usleep(10000);
            }$this->assertFileExists($dir.'/ready1');
            $this->assertFileExists($dir.'/ready2');
            touch($dir.'/start');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertJson($worker->getOutput());
            }
            $this->assertSame("sent\n", file_get_contents($dir.'/sent'));
            $this->assertSame('accepted', $row->fresh()->state);
            $this->assertSame(1, $row->fresh()->attempts);
            $this->assertDatabaseCount('communication_attempts', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
        }
    }
}
