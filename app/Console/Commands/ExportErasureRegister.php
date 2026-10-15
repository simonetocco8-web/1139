<?php

namespace App\Console\Commands;

use App\Domains\Operations\EncryptedArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportErasureRegister extends Command
{
    protected $signature = 'privacy:erasure-register {output : Nuovo file cifrato fuori da public}';

    protected $description = 'Esporta registro minimo cifrato per riapplicare le cancellazioni dopo un vecchio restore';

    public function handle(): int
    {
        $path = $this->argument('output');
        $keyFile = config('operations.backup_public_key');
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, '..') || file_exists($path) || ! is_dir(dirname($path)) || ! is_string($keyFile) || ! is_readable($keyFile)) {
            $this->error('Configurare chiave pubblica e nuovo file privato.');

            return self::FAILURE;
        }
        $parent = realpath(dirname($path));
        $public = realpath(public_path());
        if ($parent === false || $public === false || str_starts_with($parent.'/', $public.'/')) {
            return self::FAILURE;
        }
        try {
            $key = base64_decode(trim((string) file_get_contents($keyFile)), true);
            if (! is_string($key)) {
                throw new \RuntimeException;
            }
            $rows = DB::table('identity_events')->where('event', 'privacy.nonfinancial.erased')->orderBy('id')->get(['lodge_id', 'metadata', 'occurred_at'])->map(function ($row): array {
                $meta = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);

                return ['lodge_id' => $row->lodge_id, 'person_id' => $meta['person_id'], 'request_id' => $meta['request_id'], 'policy' => $meta['policy'], 'occurred_at' => $row->occurred_at];
            })->all();
            $archive = new EncryptedArchive($path.'.partial', $key);
            $archive->begin('private/privacy/erasure-register.json');
            $archive->write(json_encode(['version' => 1, 'events' => $rows], JSON_THROW_ON_ERROR));
            $archive->end();
            $archive->finish();
            if (! rename($path.'.partial', $path)) {
                throw new \RuntimeException;
            }
            $this->info('Registro minimo cifrato esportato. Conservarne una copia esterna aggiornata separatamente dai backup precedenti.');

            return self::SUCCESS;
        } catch (\Throwable) {
            if (is_file($path.'.partial')) {
                unlink($path.'.partial');
            }$this->error('Registro non esportato.');

            return self::FAILURE;
        }
    }
}
