<?php

namespace Tests\MySQL;

use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Reports\Builder;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReportSnapshotTest extends TestCase
{
    use DatabaseMigrations;

    private Lodge $lodge;

    private User $user;

    private Account $cash;

    private Account $bank;

    private int $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $o = Obedience::query()->create(['code' => 'RACE_TRE', 'name' => 'Obbedienza Concorrenza Fittizia', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'RACE_TRE', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $this->lodge = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Concorrenza Fittizia', 'is_active' => true]);
        $this->user = User::factory()->create();
        DB::table('lodge_user')->insert(['lodge_id' => $this->lodge->id, 'user_id' => $this->user->id, 'is_active' => true]);
        DB::table('lodge_user_roles')->insert(['lodge_id' => $this->lodge->id, 'user_id' => $this->user->id, 'role' => 'administrator']);
        $this->actingAs($this->user);
        session()->put(['active_lodge_id' => $this->lodge->id, 'auth.second_factor_user_id' => $this->user->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $this->user->session_version]);
        $ledger = app(Ledger::class);
        $this->cash = $ledger->account($this->lodge->id, ['name' => 'Cassa Fittizia', 'type' => 'cash', 'currency' => 'EUR', 'opening_amount' => '100.00', 'opened_on' => '2026-01-01'], bin2hex(random_bytes(24)));
        $this->bank = $ledger->account($this->lodge->id, ['name' => 'Banca Fittizia', 'type' => 'bank', 'currency' => 'EUR', 'opening_amount' => '100.00', 'opened_on' => '2026-01-01'], bin2hex(random_bytes(24)));
        $this->expense = $ledger->category($this->lodge->id, 'Spesa Fittizia', 'expense')->id;
    }

    public function test_report_keeps_one_snapshot_while_a_payment_commits_between_its_queries(): void
    {
        $income = app(Ledger::class)->category($this->lodge->id, 'Entrata concorrente Fittizia', 'income');
        $dir = sys_get_temp_dir().'/report-snapshot-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $payload = [['kind' => 'income', 'account_id' => $this->bank->id, 'amount' => '50.00', 'booked_on' => now()->toDateString(), 'description' => 'Entrata durante il report Fittizia', 'category_id' => $income->id], bin2hex(random_bytes(24))];
        $worker = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/treasury-worker.php'), (string) $this->lodge->id, (string) $this->user->id, 'post', json_encode($payload, JSON_THROW_ON_ERROR), $dir.'/ready', $dir.'/start'], base_path(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
        $worker->setTimeout(25);
        $worker->start();
        $armed = true;
        $committed = false;
        try {
            $deadline = microtime(true) + 10;
            while (! is_file($dir.'/ready') && microtime(true) < $deadline) {
                usleep(10000);
            }$this->assertFileExists($dir.'/ready');
            DB::listen(function ($event) use (&$armed, &$committed, $worker, $dir): void {
                if ($armed && str_contains(strtolower($event->sql), 'sum(') && str_contains($event->sql, 'treasury_lines') && str_contains($event->sql, 'booked_on') && str_contains($event->sql, '<')) {
                    $armed = false;
                    touch($dir.'/start');
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                    $this->assertJson($worker->getOutput(), $worker->getOutput());
                    $committed = true;
                }
            });
            $input = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()];
            $first = app(Builder::class)->make($this->lodge->id, 'financial', $input);
            $this->assertTrue($committed);
            $this->assertSame(20000, $first->metrics['Saldo finale periodo']);
            $this->assertSame(0, $first->metrics['Entrate nette']);
            $this->assertSame(20000, array_sum(array_column($first->rows, 'closing')));
            $next = app(Builder::class)->make($this->lodge->id, 'financial', $input);
            $this->assertSame(25000, $next->metrics['Saldo finale periodo']);
            $this->assertSame(5000, $next->metrics['Entrate nette']);
            $this->assertSame(25000, array_sum(array_column($next->rows, 'closing')));
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }
}
