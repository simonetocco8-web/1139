<?php

namespace App\Console\Commands;

use App\Domains\Notifications\Producer;
use Illuminate\Console\Command;

class DispatchCommunications extends Command
{
    protected $signature = 'communications:dispatch';

    protected $description = 'Recupera invii interrotti e accoda gli avvisi sulla database queue';

    public function handle(Producer $producer): int
    {
        $this->info('Job predisposti: '.$producer->pump());

        return self::SUCCESS;
    }
}
