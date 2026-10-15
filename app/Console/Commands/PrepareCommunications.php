<?php

namespace App\Console\Commands;

use App\Domains\Notifications\Producer;
use Illuminate\Console\Command;

class PrepareCommunications extends Command
{
    protected $signature = 'communications:prepare';

    protected $description = 'Predispone promemoria idempotenti con scope e preferenze correnti';

    public function handle(Producer $producer): int
    {
        $this->info('Avvisi predisposti: '.$producer->reminders());

        return self::SUCCESS;
    }
}
