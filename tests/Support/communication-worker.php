<?php

use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Notifications\Delivery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    exit(70);
}
[$script,$id,$ready,$start,$sent] = $argv;
config(['communications.enabled' => true]);
$app->instance(CodeTransport::class, new class($sent) implements CodeTransport
{
    public function __construct(private string $sent) {}

    public function available(string $channel): bool
    {
        return true;
    }

    public function send(string $channel, string $destination, string $subject, string $body): void
    {
        file_put_contents($this->sent, "sent\n", FILE_APPEND | LOCK_EX);
        usleep(250000);
    }
});
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($start) && microtime(true) < $deadline) {
    usleep(10000);
}
try {
    app(Delivery::class)->send((int) $id);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['error' => $e::class], JSON_THROW_ON_ERROR);
    exit(1);
}
