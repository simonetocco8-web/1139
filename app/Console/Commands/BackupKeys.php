<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupKeys extends Command
{
    protected $signature = 'operations:backup-keygen {directory}';

    protected $description = 'Genera una nuova coppia di chiavi: eseguire su macchina offline fidata';

    public function handle(): int
    {
        $dir = $this->argument('directory');
        if (! is_string($dir) || ! str_starts_with($dir, '/') || file_exists($dir) || str_contains($dir, '..') || str_starts_with($dir, public_path())) {
            $this->error('Usare una directory assoluta nuova fuori da public.');

            return self::FAILURE;
        }
        $parent = dirname($dir);
        while (! file_exists($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        $real = realpath($parent);
        $public = realpath(public_path());
        if ($real === false || $public === false || str_starts_with($real.'/', $public.'/')) {
            $this->error('Le chiavi devono restare fuori dalla directory pubblica.');

            return self::FAILURE;
        }
        if (! mkdir($dir, 0700, true)) {
            return self::FAILURE;
        }
        $pair = sodium_crypto_box_keypair();
        file_put_contents($dir.'/backup-public.key', base64_encode(sodium_crypto_box_publickey($pair))."\n");
        file_put_contents($dir.'/backup-secret.key', base64_encode(sodium_crypto_box_secretkey($pair))."\n");
        chmod($dir.'/backup-public.key', 0600);
        chmod($dir.'/backup-secret.key', 0600);
        sodium_memzero($pair);
        $this->info('Chiavi create. Trasferire sull’hosting solo backup-public.key; conservare backup-secret.key offline.');

        return self::SUCCESS;
    }
}
