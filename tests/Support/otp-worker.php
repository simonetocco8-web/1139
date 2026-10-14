<?php

use App\Domains\Identity\Actions\OtpService;
use Illuminate\Contracts\Console\Kernel;

// Integration-only worker, outside the public document root. No delivery or real accounts.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    exit(2);
}
session()->start();
session()->put('otp.challenge_id', $argv[1]);
session()->put('otp.nonce', 'test-concurrency-nonce');
session()->put('otp.started_at', now('UTC')->getTimestamp());
touch($argv[2]);
$deadline = microtime(true) + 10;
while (! is_file($argv[3]) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($argv[3])) {
    exit(3);
}
$result = app(OtpService::class)->verify('123456');
echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
