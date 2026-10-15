<?php

namespace App\Console\Commands;

use App\Domains\Notifications\Producer;
use App\Domains\Operations\Integrity;
use App\Models\User;
use Illuminate\Console\Command;

class CheckFinancialIntegrity extends Command
{
    protected $signature = 'operations:integrity {--alert} {--files : Verifica anche byte e digest degli allegati privati}';

    protected $description = 'Controlla ledger, saldi, obbligazioni, sorgenti e audit senza modificarli';

    public function handle(Integrity $integrity): int
    {
        $result = $integrity->check();
        if ($this->option('files')) {
            $files = $integrity->files();
            $this->info('Allegati controllati: '.$files['checked']);
            $result['issues'] = array_values(array_unique(array_merge($result['issues'], $files['issues'])));
        }
        $this->info('Logge controllate: '.$result['checked']);
        foreach ($result['issues'] as $issue) {
            $this->error($issue);
        }
        if ($result['issues'] !== [] && $this->option('alert')) {
            foreach (User::query()->where('is_platform_admin', true)->cursor() as $u) {
                app(Producer::class)->enqueue(null, $u, 'technical', null, 'financial_integrity|'.now()->toDateString());
            }
        }
        if ($result['issues'] === []) {
            $this->info('Invarianti finanziari disponibili verificati.');
        }

        return $result['issues'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
