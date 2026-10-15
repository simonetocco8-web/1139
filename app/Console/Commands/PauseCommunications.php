<?php

namespace App\Console\Commands;

use App\Domains\Identity\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PauseCommunications extends Command
{
    protected $signature = 'communications:pause {--cancel : Annulla soltanto la coda comunicazioni durante manutenzione}';

    protected $description = 'Prepara rollback del codice senza eliminare operazioni finanziarie o storico invii';

    public function handle(): int
    {
        if (! $this->option('cancel')) {
            $this->info('Avvisi annullabili: '.DB::table('communication_outbox')->whereIn('state', ['pending', 'blocked'])->count().'. Nessuna modifica.');

            return self::SUCCESS;
        }
        if (! app()->isDownForMaintenance()) {
            $this->error('Fermare i worker con il lock operativo e attivare manutenzione.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            DB::table('communication_outbox')->whereIn('state', ['pending', 'blocked'])->update(['state' => 'cancelled', 'result_code' => 'operator_paused']);
            DB::table('communication_outbox')->where('state', 'sending')->update(['state' => 'uncertain', 'result_code' => 'operator_paused']);
            DB::table('jobs')->where('queue', 'communications')->delete();
            Audit::record('communications.paused');
        }, 3);
        $this->info('Comunicazioni sospese; contabilità e storico preservati. Impostare anche COMMUNICATIONS_ENABLED=false.');

        return self::SUCCESS;
    }
}
