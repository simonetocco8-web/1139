<?php

use App\Domains\Levies\Actions\Payments;
use App\Domains\Treasury\Contracts\DisbursementGateway;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDisbursementGateway;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || ! app()->environment('testing') || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    exit(2);
}
touch($argv[3]);
$deadline = microtime(true) + 10;
while (! is_file($argv[4]) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($argv[4])) {
    exit(3);
}

$user = User::query()->findOrFail((int) $argv[5]);
Auth::login($user);
session()->put(['active_lodge_id' => (int) $argv[1], 'auth.second_factor_user_id' => $user->id, 'auth.second_factor_verified_at' => now('UTC')->getTimestamp(), 'auth.session_version' => $user->session_version]);
app()->instance(DisbursementGateway::class, new FakeDisbursementGateway);
$payment = app(Payments::class)->pay((int) $argv[1], (int) $argv[6], '60.00', now()->toDateString(), 'cash-'.$argv[1], $argv[7]);
echo json_encode(['payment' => $payment->id], JSON_THROW_ON_ERROR);
