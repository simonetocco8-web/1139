<?php

namespace App\Domains\Operations;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

final class Backup
{
    public function run(bool $force = false): string
    {
        if (! app()->isDownForMaintenance()) {
            throw new \RuntimeException('Backup coerente: attivare manutenzione e fermare scheduler/coda con il lock operativo.');
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new \RuntimeException('Il backup applicativo richiede MySQL e mysqldump 8.');
        }
        if (DB::table('information_schema.tables')->where('table_schema', DB::connection()->getDatabaseName())->where('table_type', 'BASE TABLE')->where('engine', '!=', 'InnoDB')->exists()) {
            throw new \RuntimeException('Backup coerente richiede solo tabelle InnoDB.');
        }
        $keyPath = config('operations.backup_public_key');
        $key = is_string($keyPath) && is_file($keyPath) ? base64_decode(trim((string) file_get_contents($keyPath)), true) : false;
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \RuntimeException('Configurare la chiave pubblica backup.');
        }
        $directory = (string) config('operations.backup_directory');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Archivio backup non creabile.');
        }
        $real = realpath($directory);
        $public = realpath(public_path());
        $private = realpath(storage_path('app/private'));
        if ($real === false || $public === false || $private === false || str_starts_with($real.'/', $public.'/') || str_starts_with($real.'/', $private.'/')) {
            throw new \RuntimeException('Directory backup deve essere fuori da public e dall’archivio privato.');
        }
        if (! $force) {
            $existing = DB::table('backup_runs')->where('state', 'completed')->where('recipient_fingerprint', hash('sha256', $key))->where('started_at', '>=', now('UTC')->startOfDay()->format('Y-m-d H:i:s.u'))->orderByDesc('id')->first();
            if ($existing !== null && is_string($existing->archive) && basename($existing->archive) === $existing->archive && is_file($real.'/'.$existing->archive) && hash_equals((string) $existing->sha256, (string) hash_file('sha256', $real.'/'.$existing->archive))) {
                return $existing->archive;
            }
        }
        chmod($real, 0700);
        $name = 'backup-'.now('UTC')->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.mlb';
        $partial = $real.'/'.$name.'.partial';
        $id = DB::table('backup_runs')->insertGetId(['state' => 'running', 'recipient_fingerprint' => hash('sha256', $key), 'started_at' => now('UTC')]);
        $defaults = tempnam(storage_path('framework'), 'backup-db-');
        if ($defaults === false) {
            throw new \RuntimeException('File credenziali temporaneo non creabile.');
        }chmod($defaults, 0600);
        try {
            $config = DB::connection()->getConfig();
            $quote = fn (string $v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $v).'"';
            $lines = ['[client]'];
            foreach (['host' => 'host', 'port' => 'port', 'user' => 'username', 'password' => 'password', 'socket' => 'unix_socket'] as $option => $field) {
                if (isset($config[$field]) && $config[$field] !== '') {
                    $lines[] = $option.'='.$quote((string) $config[$field]);
                }
            }
            if (! empty($config['options'][\PDO::MYSQL_ATTR_SSL_CA])) {
                $lines[] = 'ssl-ca='.$quote((string) $config['options'][\PDO::MYSQL_ATTR_SSL_CA]);
            }
            file_put_contents($defaults, implode("\n", $lines)."\n");
            $archive = new EncryptedArchive($partial, $key);
            $archive->begin('database.sql');
            $process = new Process([(string) config('operations.mysqldump'), '--defaults-extra-file='.$defaults, '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--no-tablespaces', '--skip-comments', '--set-gtid-purged=OFF', '--column-statistics=0', '--', (string) $config['database']]);
            $process->setTimeout(3600);
            $process->run(function (string $type, string $data) use ($archive, $process): void {
                if ($type === Process::OUT) {
                    $archive->write($data);
                }$process->clearOutput();
                $process->clearErrorOutput();
            });
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Dump MySQL fallito. Verificare client, privilegi e spazio senza pubblicare credenziali.');
            }
            $archive->end();
            $archive->begin('application-keys.json');
            $archive->write(json_encode(['APP_KEY' => config('app.key'), 'OTP_SECRET' => config('identity.otp_secret')], JSON_THROW_ON_ERROR));
            $archive->end();
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($private, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isLink()) {
                    throw new \RuntimeException('Link simbolici non ammessi nell’archivio privato.');
                }
                if (! $entry->isFile()) {
                    continue;
                }$relative = substr($entry->getPathname(), strlen($private) + 1);
                $archive->begin('private/'.$relative);
                $f = fopen($entry->getPathname(), 'rb');
                if ($f === false) {
                    throw new \RuntimeException('File privato non leggibile.');
                }
                try {
                    while (! feof($f)) {
                        $chunk = fread($f, 65536);
                        if ($chunk === false) {
                            throw new \RuntimeException('File privato non leggibile.');
                        }$archive->write($chunk);
                    }
                } finally {
                    fclose($f);
                }$archive->end();
            }
            $archive->finish();
            if (! rename($partial, $real.'/'.$name)) {
                throw new \RuntimeException('Backup non finalizzato.');
            }
            $digest = hash_file('sha256', $real.'/'.$name);
            if (! is_string($digest)) {
                throw new \RuntimeException('Digest backup non verificabile.');
            }
            DB::table('backup_runs')->where('id', $id)->update(['state' => 'completed', 'archive' => $name, 'sha256' => $digest, 'completed_at' => now('UTC')]);

            return $name;
        } catch (\Throwable) {
            if (is_file($partial)) {
                unlink($partial);
            }DB::table('backup_runs')->where('id', $id)->update(['state' => 'failed', 'completed_at' => now('UTC')]);
            throw new \RuntimeException('Backup non completato; nessun archivio dichiarato valido. Verificare la configurazione operativa.');
        } finally {
            if (is_file($defaults)) {
                unlink($defaults);
            }
        }
    }
}
