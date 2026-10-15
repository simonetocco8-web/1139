<?php

namespace App\Console\Commands;

use App\Domains\Identity\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QuarantineRestoredRuntime extends Command
{
    protected $signature = 'operations:quarantine-restored {--confirm-restore}';

    protected $description = 'Dopo restore: revoca sessioni, token e job recuperati; conserva i fatti contabili';

    public function handle(): int
    {
        if (! $this->option('confirm-restore') || ! app()->isDownForMaintenance()) {
            $this->error('Richiesti manutenzione e --confirm-restore, soltanto sul ripristino verificato.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            DB::table('users')->increment('session_version');
            DB::table('users')->update(['remember_token' => null, 'is_active' => false]);
            DB::table('sessions')->delete();
            DB::table('password_reset_tokens')->delete();
            DB::table('identity_invitations')->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
            DB::table('otp_challenges')->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now('UTC')]);
            DB::table('identity_recovery_codes')->whereNull('consumed_at')->update(['consumed_at' => now('UTC')]);
            DB::table('assisted_access_grants')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
            DB::table('communication_outbox')->whereIn('state', ['pending', 'blocked'])->update(['state' => 'cancelled', 'result_code' => 'restore_quarantine']);
            DB::table('communication_outbox')->where('state', 'sending')->update(['state' => 'uncertain', 'result_code' => 'restore_quarantine']);
            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();
            Audit::record('operations.restore.quarantined');
        }, 3);
        $this->info('Runtime ripristinato messo in quarantena. Nessuna registrazione finanziaria eliminata. Account disattivati: identità, recapiti e ruoli richiedono rivalidazione umana prima di qualsiasi riattivazione.');

        return self::SUCCESS;
    }
}
