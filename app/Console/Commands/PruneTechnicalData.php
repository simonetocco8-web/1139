<?php

namespace App\Console\Commands;

use App\Domains\Operations\Retention;
use Illuminate\Console\Command;

class PruneTechnicalData extends Command
{
    protected $signature = 'operations:prune {--apply : Applica solo la politica approvata configurata}';

    protected $description = 'Anteprima o applicazione della conservazione tecnica; non elimina contabilità';

    public function handle(Retention $retention): int
    {
        try {
            foreach ($retention->run((bool) $this->option('apply')) as $table => $count) {
                $this->line($table.': '.$count);
            }$this->info($this->option('apply') ? 'Politica configurata applicata.' : 'Sola anteprima: nessun dato eliminato.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Politica assente o non valida. Nessuna cancellazione completata.');

            return self::FAILURE;
        }
    }
}
