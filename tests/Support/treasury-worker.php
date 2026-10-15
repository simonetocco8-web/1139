<?php

use App\Domains\Capitations\Actions\Payments;
use App\Domains\Treasury\Actions\Ledger;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    exit(2);
}
$user = User::query()->findOrFail((int) $argv[2]);
Auth::login($user);
session()->put(['active_lodge_id' => (int) $argv[1], 'auth.second_factor_user_id' => $user->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $user->session_version]);
$payload = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
touch($argv[5]);
$deadline = microtime(true) + 10;
while (! is_file($argv[6]) && microtime(true) < $deadline) {
    usleep(10000);
}if (! is_file($argv[6])) {
    exit(3);
}
try {
    if ($argv[3] === 'capitation') {
        $r = app(Payments::class)->receive((int) $argv[1], $payload[0], '25.00', now()->toDateString(), $payload[1], $payload[2]);
    } else {
        $r = app(Ledger::class)->post((int) $argv[1], $payload[0], $payload[1]);
    }echo json_encode(['status' => 'ok', 'id' => $r->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $e) {
    echo json_encode(['status' => 'blocked', 'errors' => array_keys($e->errors())], JSON_THROW_ON_ERROR);
}
