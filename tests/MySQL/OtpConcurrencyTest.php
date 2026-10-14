<?php

namespace Tests\MySQL;

use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Secret;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OtpConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_can_consume_same_challenge_only_once(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        OtpChallenge::query()->create(['id' => $id, 'user_id' => $user->id, 'purpose' => 'login', 'channel' => 'email', 'session_binding' => Secret::digest('session', 'test-concurrency-nonce'), 'code_digest' => Secret::digest('otp', $id.'|login|123456'), 'credential_version' => 0, 'payload' => ['destination_digest' => Secret::digest('destination', $user->email)], 'delivery_state' => 'accepted', 'expires_at' => now('UTC')->addMinutes(5), 'last_sent_at' => now('UTC')]);
        $dir = sys_get_temp_dir().'/identity-concurrency-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/otp-worker.php'), $id, $dir.'/ready'.$i, $dir.'/start'], base_path(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($dir.'/ready0') || ! is_file($dir.'/ready1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($dir.'/ready0');
            $this->assertFileExists($dir.'/ready1');
            touch($dir.'/start');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['result'];
            }
            $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'login')));
            $this->assertSame(1, count(array_filter($results, fn ($result) => $result === null)));
            $this->assertSame(1, OtpChallenge::query()->findOrFail($id)->attempts);
            $this->assertSame(1, DB::table('identity_events')->where('event', 'otp.consumed')->count());
            $this->assertNotNull(OtpChallenge::query()->findOrFail($id)->consumed_at);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
        }
    }
}
