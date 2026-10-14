<?php

namespace App\Console\Commands;

use App\Domains\Identity\Actions\Invitations;
use Illuminate\Console\Command;

class InviteSuperUser extends Command
{
    protected $signature = 'identity:invite-superuser {email}';

    protected $description = 'Invia via SMTP l’invito del primo Super User; nessuna password o token in console';

    public function handle(Invitations $invites): int
    {
        try {
            $email = $this->argument('email');
            if (! is_string($email)) {
                throw new \InvalidArgumentException('Email richiesta.');
            }
            $invites->platform($email, first: true);
        } catch (\Throwable) {
            $this->error('Invito non inviato. Verifica SMTP e che non sia già presente un Super User o un invito iniziale valido.');

            return self::FAILURE;
        }
        $this->info('Richiesta email accettata dal canale configurato. Nessun account attivato prima dell’accettazione.');

        return self::SUCCESS;
    }
}
