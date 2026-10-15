<?php

namespace App\Console\Commands;

use App\Domains\Operations\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CreateEncryptedBackup extends Command
{
    protected $signature = 'operations:backup {--force : Nuovo backup anche se già completato oggi}';

    protected $description = 'Crea backup MySQL e file privati cifrato per il destinatario offline';

    public function handle(Backup $backup): int
    {
        $lock = Cache::lock('operations-backup', 3600);
        if (! $lock->get()) {
            $this->error('Un backup è già in esecuzione.');

            return self::FAILURE;
        }
        try {
            $this->info('Backup completato: '.$backup->run((bool) $this->option('force')));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backup non completato. Verificare manutenzione, configurazione e strumenti richiesti.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
