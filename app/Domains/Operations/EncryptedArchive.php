<?php

namespace App\Domains\Operations;

final class EncryptedArchive
{
    private const MAGIC = "MLBACK1\n";

    /** @var resource */
    private $file;

    private string $state;

    private ?\HashContext $hash = null;

    private int $bytes = 0;

    private bool $complete = false;

    public function __construct(string $path, string $publicKey)
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \RuntimeException('Chiave pubblica backup non valida.');
        }
        $f = fopen($path, 'x+b');
        if ($f === false) {
            throw new \RuntimeException('Archivio non creabile.');
        }
        $this->file = $f;
        chmod($path, 0600);
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$this->state,$header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $this->raw(self::MAGIC.sodium_crypto_box_seal($key, $publicKey).$header);
        sodium_memzero($key);
    }

    public static function validPath(string $path): bool
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return in_array($path, ['database.sql', 'application-keys.json'], true) || str_starts_with($path, 'private/');
    }

    public function begin(string $path): void
    {
        if ($this->hash !== null || $this->complete || ! self::validPath($path)) {
            throw new \RuntimeException('Sequenza archivio non valida.');
        }
        $this->record('M'.json_encode(['path' => $path], JSON_THROW_ON_ERROR));
        $this->hash = hash_init('sha256');
        $this->bytes = 0;
    }

    public function write(string $bytes): void
    {
        if ($this->hash === null) {
            throw new \RuntimeException('Voce archivio non aperta.');
        }
        foreach (str_split($bytes, 65536) as $chunk) {
            hash_update($this->hash, $chunk);
            $this->bytes += strlen($chunk);
            $this->record('D'.$chunk);
        }
    }

    public function end(): void
    {
        if ($this->hash === null) {
            throw new \RuntimeException('Voce archivio non aperta.');
        }
        $this->record('E'.json_encode(['sha256' => hash_final($this->hash), 'bytes' => $this->bytes], JSON_THROW_ON_ERROR));
        $this->hash = null;
    }

    public function finish(): void
    {
        if ($this->hash !== null || $this->complete) {
            throw new \RuntimeException('Archivio incompleto.');
        }
        $this->record('Z', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        if (! fflush($this->file) || ! fsync($this->file)) {
            throw new \RuntimeException('Sincronizzazione backup fallita.');
        }
        $this->complete = true;
        fclose($this->file);
    }

    public function __destruct()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    private function record(string $plain, int $tag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE): void
    {
        $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);
        $this->raw(pack('N', strlen($encrypted)).$encrypted);
    }

    private function raw(string $bytes): void
    {
        while ($bytes !== '') {
            $n = fwrite($this->file, $bytes);
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Scrittura backup fallita.');
            }$bytes = substr($bytes, $n);
        }
    }

    /** @return array<string,array{bytes:int,sha256:string}> */
    public static function extract(string $archive, string $secret, string $destination): array
    {
        if (strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES || file_exists($destination) || is_link($destination)) {
            throw new \RuntimeException('Usare chiave valida e directory di ripristino nuova.');
        }
        if (! mkdir($destination, 0700, true)) {
            throw new \RuntimeException('Directory di ripristino non creabile.');
        }
        $f = fopen($archive, 'rb');
        $out = null;
        $seen = [];
        $manifest = [];
        $total = 0;
        $hash = null;
        $path = null;
        $bytes = 0;
        try {
            if ($f === false || self::read($f, 8) !== self::MAGIC) {
                throw new \RuntimeException('Formato backup non valido.');
            }
            $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));
            $key = sodium_crypto_box_seal_open(self::read($f, SODIUM_CRYPTO_BOX_SEALBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES), $pair);
            if ($key === false) {
                throw new \RuntimeException('Chiave di ripristino non corrispondente.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($f, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            sodium_memzero($key);
            while (true) {
                $unpacked = unpack('Nsize', self::read($f, 4));
                if ($unpacked === false) {
                    throw new \RuntimeException('Frame backup non valido.');
                }
                $length = $unpacked['size'];
                if ($length < 18 || $length > 1048576) {
                    throw new \RuntimeException('Frame backup non valido.');
                }
                $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::read($f, $length));
                if ($decoded === false) {
                    throw new \RuntimeException('Integrità del backup non valida.');
                }
                [$plain,$tag] = $decoded;
                $type = substr($plain, 0, 1);
                $body = substr($plain, 1);
                if ($type === 'Z') {
                    if ($plain !== 'Z' || $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL || $out !== null || fgetc($f) !== false) {
                        throw new \RuntimeException('Chiusura backup non valida.');
                    }

                    return $manifest;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new \RuntimeException('Tag backup non valido.');
                }
                if ($type === 'M') {
                    if ($out !== null) {
                        throw new \RuntimeException('Voce backup incompleta.');
                    }
                    $meta = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                    $path = $meta['path'] ?? null;
                    if (! is_string($path) || ! self::validPath($path) || isset($seen[$path])) {
                        throw new \RuntimeException('Percorso backup non valido.');
                    }
                    $seen[$path] = true;
                    $target = $destination.'/'.$path;
                    if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true)) {
                        throw new \RuntimeException('Directory non creabile.');
                    }
                    $out = fopen($target, 'xb');
                    if ($out === false) {
                        throw new \RuntimeException('File non creabile.');
                    }chmod($target, 0600);
                    $hash = hash_init('sha256');
                    $bytes = 0;
                } elseif ($type === 'D') {
                    if (! is_resource($out) || $hash === null) {
                        throw new \RuntimeException('Voce backup mancante.');
                    }
                    $bytes += strlen($body);
                    $total += strlen($body);
                    if ($total > 53687091200 || fwrite($out, $body) !== strlen($body)) {
                        throw new \RuntimeException('Limite o scrittura ripristino non valida.');
                    }hash_update($hash, $body);
                } elseif ($type === 'E') {
                    if (! is_resource($out) || $hash === null) {
                        throw new \RuntimeException('Voce backup mancante.');
                    }
                    $end = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                    $digest = hash_final($hash);
                    if (($end['bytes'] ?? null) !== $bytes || ! is_string($end['sha256'] ?? null) || ! hash_equals($end['sha256'], $digest)) {
                        throw new \RuntimeException('File ripristinato non integro.');
                    }
                    fclose($out);
                    $out = null;
                    $hash = null;
                    $manifest[$path] = ['bytes' => $bytes, 'sha256' => $digest];
                } else {
                    throw new \RuntimeException('Record backup non valido.');
                }
            }
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }rmdir($destination);
            throw $e;
        } finally {
            if (is_resource($f)) {
                fclose($f);
            }
        }
    }

    /** @param resource $f */
    private static function read($f, int $length): string
    {
        if ($length < 1) {
            throw new \RuntimeException('Lunghezza backup non valida.');
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($f, max(1, $length - strlen($bytes)));
            if ($part === false || $part === '') {
                throw new \RuntimeException('Backup troncato o illeggibile.');
            }$bytes .= $part;
        }

        return $bytes;
    }
}
