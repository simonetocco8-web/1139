<?php

namespace App\Domains\Membership\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Secret;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\ImportBatch;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CsvImport
{
    public const HEADERS = ['reference', 'first_name', 'last_name', 'birth_date', 'email', 'phone', 'address', 'starts_on', 'ends_on', 'status', 'administrative_exemption', 'exemption_reason'];

    public function __construct(private Members $members) {}

    /** @return array{token:string,batch:ImportBatch} */
    public function preview(int $lodgeId, string $csv): array
    {
        $this->members->guard($lodgeId, 'members.manage');
        if (strlen($csv) > 262144 || ! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw ValidationException::withMessages(['csv' => 'CSV UTF-8 richiesto, massimo 256 KiB e senza byte nulli.']);
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Impossibile leggere il CSV.');
        }
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if ($header !== self::HEADERS) {
                throw ValidationException::withMessages(['csv' => 'Intestazione non valida. Scarica il modello CSV.']);
            }
            $rows = [];
            $errors = [];
            $seen = [];
            while (($line = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                $n = count($rows) + 2;
                if ($n > 201) {
                    throw ValidationException::withMessages(['csv' => 'Massimo 200 righe per importazione.']);
                }
                if (count($line) !== count(self::HEADERS)) {
                    throw ValidationException::withMessages(['csv' => 'Numero colonne non valido alla riga '.$n.'.']);
                }
                $row = array_combine(self::HEADERS, array_map(fn ($v) => $v === '' ? null : $v, $line));
                $rows[] = $row;
                try {
                    $values = $this->members->personValues($lodgeId, $row);
                    $this->members->enrollmentValues($row);
                    if (isset($seen[$values['reference']])) {
                        throw ValidationException::withMessages(['reference' => 'Codice ripetuto nel file.']);
                    }
                    $seen[$values['reference']] = true;
                } catch (ValidationException $e) {
                    $errors[$n] = $e->errors();
                }
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['csv' => 'Il file non contiene iscritti.']);
            }
        } finally {
            fclose($stream);
        }
        $nonce = bin2hex(random_bytes(32));
        session()->put('member.import_nonce', $nonce);
        $token = bin2hex(random_bytes(32));
        $batch = ImportBatch::query()->create(['lodge_id' => $lodgeId, 'actor_id' => VerifiedSession::user()->id, 'token_digest' => hash('sha256', $token), 'session_binding' => Secret::digest('member-import-session', $nonce),
            'payload' => ['rows' => $rows, 'errors' => $errors], 'expires_at' => now('UTC')->addMinutes(15)]);

        return ['token' => $token, 'batch' => $batch];
    }

    public function find(int $lodgeId, string $token): ImportBatch
    {
        $this->members->guard($lodgeId, 'members.manage');
        $nonce = session('member.import_nonce');
        abort_unless(is_string($nonce), 404);

        return ImportBatch::query()->where('lodge_id', $lodgeId)->where('actor_id', VerifiedSession::user()->id)->where('token_digest', hash('sha256', $token))
            ->where('session_binding', Secret::digest('member-import-session', $nonce))->whereNull('consumed_at')->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->firstOrFail();
    }

    public function confirm(int $lodgeId, string $token): int
    {
        $this->members->guard($lodgeId, 'members.manage');

        return DB::transaction(function () use ($lodgeId, $token): int {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $batch = $this->find($lodgeId, $token);
            $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_if($batch->consumed_at !== null, 409);
            $payload = $batch->payload;
            if ($payload['errors'] !== []) {
                throw ValidationException::withMessages(['csv' => 'Correggi il file e genera una nuova anteprima.']);
            }
            foreach ($payload['rows'] as $row) {
                $this->members->create($lodgeId, $row);
            }
            $batch->consumed_at = now('UTC');
            $batch->payload = ['rows' => [], 'errors' => []];
            $batch->save();
            Audit::record('membership.import.confirmed', VerifiedSession::user()->id, $lodgeId, metadata: ['batch_id' => $batch->id, 'count' => count($payload['rows'])]);

            return count($payload['rows']);
        }, 3);
    }
}
