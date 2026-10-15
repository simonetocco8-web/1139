<?php

namespace App\Console\Commands;

use App\Domains\Operations\Health;
use Illuminate\Console\Command;

class OperationsCheck extends Command
{
    protected $signature = 'operations:check {--deployment} {--alert}';

    protected $description = 'Verifica cron, coda, backup e configurazione senza stampare segreti';

    public function handle(Health $health): int
    {
        try {
            $issues = $health->issues((bool) $this->option('deployment'));
            if ($this->option('alert')) {
                $health->alert();
            }foreach ($issues as $issue) {
                $this->warn($issue);
            }if ($issues === []) {
                $this->info('Controlli disponibili superati. Non sostituiscono il collaudo dell’hosting.');
            }

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Verifica tecnica non completata. Controllare database e monitoraggio esterno.');

            return self::FAILURE;
        }
    }
}
