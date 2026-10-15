<?php

namespace Tests\Feature;

use App\Domains\Operations\EncryptedArchive;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EncryptedBackupTest extends TestCase
{
    private string $directory;

    private string $public;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ml-backup-test-'.bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        $pair = sodium_crypto_box_keypair();
        $this->public = sodium_crypto_box_publickey($pair);
        $this->secret = sodium_crypto_box_secretkey($pair);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function archive(): string
    {
        $path = $this->directory.'/backup.mlb';
        $writer = new EncryptedArchive($path, $this->public);
        $writer->begin('database.sql');
        $writer->write('SQL_FITTIZIO_SEGRETO'.str_repeat('x', 200000));
        $writer->end();
        $writer->begin('application-keys.json');
        $writer->write('{"APP_KEY":"CHIAVE_FITTIZIA"}');
        $writer->end();
        $writer->begin('private/treasury/1/documento');
        $writer->write("Documento Fittizio\0\xFF");
        $writer->end();
        $writer->finish();

        return $path;
    }

    public function test_encrypted_stream_roundtrip_preserves_sql_keys_private_binary_and_permissions(): void
    {
        $path = $this->archive();
        $this->assertStringNotContainsString('SQL_FITTIZIO_SEGRETO', file_get_contents($path));
        $this->assertStringNotContainsString('CHIAVE_FITTIZIA', file_get_contents($path));
        $manifest = EncryptedArchive::extract($path, $this->secret, $this->directory.'/restore');
        $this->assertCount(3, $manifest);
        $this->assertSame("Documento Fittizio\0\xFF", file_get_contents($this->directory.'/restore/private/treasury/1/documento'));
        $this->assertSame(hash_file('sha256', $this->directory.'/restore/database.sql'), $manifest['database.sql']['sha256']);
        $this->assertSame(0600, fileperms($this->directory.'/restore/database.sql') & 0777);
        $this->assertSame(0700, fileperms($this->directory.'/restore') & 0777);
    }

    public function test_tampered_backup_is_rejected_and_partial_plaintext_removed(): void
    {
        $path = $this->archive();
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        file_put_contents($path, $bytes);
        try {
            EncryptedArchive::extract($path, $this->secret, $this->directory.'/bad');
            $this->fail('Manomissione accettata.');
        } catch (\RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->directory.'/bad');
        }
    }

    public function test_truncated_backup_without_final_tag_is_rejected(): void
    {
        $path = $this->archive();
        file_put_contents($path, substr(file_get_contents($path), 0, -22));
        $this->expectException(\RuntimeException::class);
        EncryptedArchive::extract($path, $this->secret, $this->directory.'/truncated');
    }

    public function test_wrong_secret_key_cannot_decrypt(): void
    {
        $path = $this->archive();
        $pair = sodium_crypto_box_keypair();
        $this->expectException(\RuntimeException::class);
        EncryptedArchive::extract($path, sodium_crypto_box_secretkey($pair), $this->directory.'/wrong');
    }

    public function test_existing_restore_directory_and_files_are_never_overwritten(): void
    {
        $path = $this->archive();
        mkdir($this->directory.'/existing');
        file_put_contents($this->directory.'/existing/important', 'Fittizio');
        try {
            EncryptedArchive::extract($path, $this->secret, $this->directory.'/existing');
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertSame('Fittizio', file_get_contents($this->directory.'/existing/important'));
        }
    }

    public function test_path_traversal_and_absolute_paths_are_rejected_even_in_authenticated_frames(): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state,$header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $frame = sodium_crypto_secretstream_xchacha20poly1305_push($state, 'M{"path":"private/../../outside"}');
        $path = $this->directory.'/malicious.mlb';
        file_put_contents($path, "MLBACK1\n".sodium_crypto_box_seal($key, $this->public).$header.pack('N', strlen($frame)).$frame);
        try {
            EncryptedArchive::extract($path, $this->secret, $this->directory.'/bad');
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertFileDoesNotExist($this->directory.'/outside');
            $this->assertDirectoryDoesNotExist($this->directory.'/bad');
        }
        foreach (['/tmp/test', '../test', 'private/../test', 'private/a\\b', 'database.sql/../x'] as $p) {
            $this->assertFalse(EncryptedArchive::validPath($p));
        }
    }

    public function test_key_generation_never_prints_key_material_and_restore_command_checks_bundle(): void
    {
        $this->artisan('operations:backup-keygen', ['directory' => $this->directory.'/keys'])->assertSuccessful();
        $secret = file_get_contents($this->directory.'/keys/backup-secret.key');
        $this->assertSame(0600, fileperms($this->directory.'/keys/backup-secret.key') & 0777);
        $this->assertSame(32, strlen(base64_decode(trim($secret), true)));
        file_put_contents($this->directory.'/our-secret.key', base64_encode($this->secret));
        $this->artisan('operations:restore-check', ['archive' => $this->archive(), 'directory' => $this->directory.'/checked', '--secret-key-file' => $this->directory.'/our-secret.key', '--sha256' => hash_file('sha256', $this->directory.'/backup.mlb')])->assertSuccessful();
        $this->assertFileExists($this->directory.'/checked/database.sql');
    }

    public function test_key_generation_rejects_a_symlink_alias_to_the_public_directory(): void
    {
        mkdir($this->directory.'/web', 0700);
        app()->usePublicPath($this->directory.'/web');
        symlink($this->directory.'/web', $this->directory.'/alias');
        $this->artisan('operations:backup-keygen', ['directory' => $this->directory.'/alias/keys'])->assertFailed();
        $this->assertDirectoryDoesNotExist($this->directory.'/web/keys');
    }

    public function test_restore_requires_a_matching_external_catalog_digest(): void
    {
        $path = $this->archive();
        file_put_contents($this->directory.'/secret.key', base64_encode($this->secret));
        $this->artisan('operations:restore-check', ['archive' => $path, 'directory' => $this->directory.'/rejected', '--secret-key-file' => $this->directory.'/secret.key', '--sha256' => str_repeat('0', 64)])->assertFailed();
        $this->assertDirectoryDoesNotExist($this->directory.'/rejected');
    }
}
