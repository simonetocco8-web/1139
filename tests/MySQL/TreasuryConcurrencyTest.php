<?php

namespace Tests\MySQL;

use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Treasury\Actions\Balances;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TreasuryConcurrencyTest extends TestCase
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

    private function workers(string $mode, array $first, array $second): array
    {
        $dir = sys_get_temp_dir().'/treasury-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ([$first, $second] as $i => $payload) {
                $p = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/treasury-worker.php'), (string) $this->lodge->id, (string) $this->user->id, $mode, json_encode($payload, JSON_THROW_ON_ERROR), $dir.'/ready'.$i, $dir.'/start'], base_path(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
                $p->setTimeout(25);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($dir.'/ready0') || ! is_file($dir.'/ready1')) && microtime(true) < $deadline) {
                usleep(10000);
            }$this->assertFileExists($dir.'/ready0');
            $this->assertFileExists($dir.'/ready1');
            touch($dir.'/start');
            $results = [];
            foreach ($workers as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $this->assertJson($p->getOutput(), $p->getOutput().$p->getErrorOutput());
                $results[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($workers as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }

    public function test_simultaneous_cash_spends_cannot_overdraw(): void
    {
        $base = ['kind' => 'expense', 'account_id' => $this->cash->id, 'amount' => '80.00', 'booked_on' => now()->toDateString(), 'description' => 'Spesa concorrente Fittizia', 'category_id' => $this->expense];
        $results = $this->workers('post', [$base, bin2hex(random_bytes(24))], [$base, bin2hex(random_bytes(24))]);
        $this->assertEqualsCanonicalizing(['ok', 'blocked'], array_column($results, 'status'));
        $this->assertSame(2000, Balances::account($this->lodge->id, $this->cash->id));
        $this->assertDatabaseCount('treasury_operations', 3);
    }

    public function test_same_payment_key_posts_only_once(): void
    {
        $key = bin2hex(random_bytes(24));
        $base = ['kind' => 'expense', 'account_id' => $this->cash->id, 'amount' => '30.00', 'booked_on' => now()->toDateString(), 'description' => 'Spesa concorrente Fittizia', 'category_id' => $this->expense];
        $results = $this->workers('post', [$base, $key], [$base, $key]);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(7000, Balances::account($this->lodge->id, $this->cash->id));
        $this->assertDatabaseCount('treasury_operations', 3);
    }

    public function test_opposite_transfers_conserve_both_balances(): void
    {
        $a = ['kind' => 'transfer', 'account_id' => $this->cash->id, 'target_id' => $this->bank->id, 'amount' => '30.00', 'booked_on' => now()->toDateString(), 'description' => 'Giroconto concorrente Fittizio'];
        $b = array_replace($a, ['account_id' => $this->bank->id, 'target_id' => $this->cash->id]);
        $this->workers('post', [$a, bin2hex(random_bytes(24))], [$b, bin2hex(random_bytes(24))]);
        $this->assertSame(10000, Balances::account($this->lodge->id, $this->cash->id));
        $this->assertSame(10000, Balances::account($this->lodge->id, $this->bank->id));
        $this->assertDatabaseCount('treasury_operations', 4);
        $this->assertEquals(0, DB::table('treasury_lines')->sum('amount'));
    }

    public function test_concurrent_real_capitation_receipts_share_one_financial_post(): void
    {
        $person = Person::query()->forceCreate(['lodge_id' => $this->lodge->id, 'reference' => 'RACE_CAP', 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio', 'version' => 1]);
        $payload = [$person->id, (string) $this->cash->id, bin2hex(random_bytes(24))];
        $results = $this->workers('capitation', $payload, $payload);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(12500, Balances::account($this->lodge->id, $this->cash->id));
        $this->assertDatabaseCount('capitation_receipts', 1);
        $this->assertDatabaseCount('treasury_operations', 3);
        $this->assertDatabaseCount('treasury_source_links', 1);
    }
}
