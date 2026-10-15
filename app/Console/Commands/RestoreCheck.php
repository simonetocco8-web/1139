<?php

namespace App\Console\Commands;

use App\Domains\Operations\EncryptedArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RestoreCheck extends Command
{
    protected $signature = 'operations:restore-check {archive} {directory} {--secret-key-file=} {--sha256= : Digest atteso da catalogo esterno fidato} {--purpose=backup : backup oppure erasure-register}';

    protected $description = 'Verifica e decifra in directory nuova privata; non importa o modifica database';

    public function handle(): int
    {
        $archive = $this->argument('archive');
        $dir = $this->argument('directory');
        $file = $this->option('secret-key-file');
        if (! is_string($archive) || ! is_string($dir) || ! str_starts_with($dir, '/') || str_contains($dir, '..') || ! is_string($file) || ! is_readable($file)) {
            return self::FAILURE;
        }
        $expected = $this->option('sha256');
        if (! is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1 || ! is_file($archive) || ! hash_equals($expected, (string) hash_file('sha256', $archive))) {
            $this->error('Fornire --sha256 dal catalogo esterno fidato: backup non validato.');

            return self::FAILURE;
        }
        $parent = dirname($dir);
        while (! file_exists($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        $real = realpath($parent);
        $public = realpath(public_path());
        if ($real === false || $public === false || str_starts_with($real.'/', $public.'/')) {
            $this->error('Ripristino consentito solo fuori dalla directory pubblica.');

            return self::FAILURE;
        }
        try {
            $secret = base64_decode(trim((string) file_get_contents($file)), true);
            if (! is_string($secret)) {
                throw new \RuntimeException;
            }
            $manifest = EncryptedArchive::extract($archive, $secret, $dir);
            sodium_memzero($secret);
            $purpose = $this->option('purpose');
            $valid = $purpose === 'backup' ? isset($manifest['database.sql'],$manifest['application-keys.json']) : ($purpose === 'erasure-register' && isset($manifest['private/privacy/erasure-register.json']));
            if (! $valid) {
                File::deleteDirectory($dir);
                throw new \RuntimeException;
            }
            $this->info('Archivio verificato ed estratto. File: '.count($manifest).'. Importare soltanto in un database isolato, poi verificare integrità e allegati.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Ripristino non validato. Non usare i dati estratti.');

            return self::FAILURE;
        }
    }
}
