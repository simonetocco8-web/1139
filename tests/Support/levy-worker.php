<?php

use App\Domains\Levies\Actions\Generator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
$count = app(Generator::class)->run((int) $argv[1], $argv[2]);
echo json_encode(['created' => $count], JSON_THROW_ON_ERROR);
