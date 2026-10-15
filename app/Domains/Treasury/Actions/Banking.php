<?php

namespace App\Domains\Treasury\Actions;

use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\ImportBatch;
use App\Domains\Treasury\Models\Line;
use App\Domains\Treasury\Models\Reconciliation;
use App\Domains\Treasury\Models\StatementEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Banking
{
    public function __construct(private Access $access) {}

    /** @param array<string,mixed> $input
     * @return array{booked_on:string,amount:string,description:string,reference:string,reference_digest:string,fingerprint:string} */
    public function row(array $input): array
    {
        $v = Validator::make($input, ['booked_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'], 'amount' => ['required', 'string'], 'description' => ['required', 'string', 'min:3', 'max:500'], 'reference' => ['required', 'string', 'max:160']])->validate();
        $cents = Money::cents($v['amount'], true);
        if ($cents === 0) {
            throw ValidationException::withMessages(['amount' => 'Riga bancaria di importo nullo.']);
        }

        return ['booked_on' => $v['booked_on'], 'amount' => Money::decimal($cents), 'description' => $v['description'], 'reference' => $v['reference'], 'reference_digest' => hash('sha256', trim($v['reference'])), 'fingerprint' => hash('sha256', json_encode([$v['booked_on'], $cents, mb_strtolower(trim($v['description']))], JSON_THROW_ON_ERROR))];
    }

    public function bank(int $lodgeId, int $accountId): Account
    {
        $a = $this->access->account($lodgeId, $accountId);
        abort_unless($a->type === 'bank', 422, 'La riconciliazione richiede un conto bancario.');

        return $a;
    }

    /** @param array<string,mixed> $input */
    public function entry(int $lodgeId, int $accountId, array $input): StatementEntry
    {
        $source = $this->access->guard($lodgeId, 'update');
        $row = $this->row($input);

        return DB::transaction(function () use ($source, $lodgeId, $accountId, $row): StatementEntry {
            $this->access->lock($lodgeId, $source->obedience_id, 'update');
            $a = $this->bank($lodgeId, $accountId);
            abort_if($row['booked_on'] < $a->opened_on->toDateString(), 422);
            $old = $this->duplicate($lodgeId, $accountId, $row);
            if ($old) {
                abort_unless($old->fingerprint === $row['fingerprint'], 409, 'Riferimento bancario già utilizzato con dati differenti.');

                return $old;
            }$e = StatementEntry::query()->create($row + ['lodge_id' => $lodgeId, 'account_id' => $accountId, 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
            Audit::record('treasury.statement.created', VerifiedSession::user()->id, $lodgeId, metadata: ['entry_id' => $e->id]);

            return $e;
        }, 3);
    }

    /** @param array{reference_digest:string,fingerprint:string} $row */
    public function duplicate(int $lodgeId, int $accountId, array $row): ?StatementEntry
    {
        return StatementEntry::query()->where('lodge_id', $lodgeId)->where('account_id', $accountId)->where('reference_digest', $row['reference_digest'])->first();
    }

    /** @return Builder<Line> */
    public function candidates(int $lodgeId, StatementEntry $entry): Builder
    {
        $this->access->guard($lodgeId, 'update');
        abort_unless($entry->lodge_id === $lodgeId, 404);

        return Line::query()->where('lodge_id', $lodgeId)->where('account_id', $entry->account_id)->where('bucket', 'financial')->where('amount', $entry->amount)->whereDoesntHave('reconciliations', fn ($q) => $q->whereNull('ended_at'))->whereHas('operation', fn ($q) => $q->whereNull('reverses_id')->whereDoesntHave('reversal')->where('kind', '<>', 'opening'));
    }

    public function match(int $lodgeId, int $entryId, int $lineId): Reconciliation
    {
        $source = $this->access->guard($lodgeId, 'update');

        return DB::transaction(function () use ($source, $lodgeId, $entryId, $lineId): Reconciliation {
            $this->access->lock($lodgeId, $source->obedience_id, 'update');
            $e = StatementEntry::query()->where('lodge_id', $lodgeId)->whereKey($entryId)->firstOrFail();
            $current = $e->reconciliations()->whereNull('ended_at')->first();
            if ($current) {
                abort_unless($current->line_id === $lineId, 409, 'Riga bancaria già riconciliata.');

                return $current;
            }
            $line = $this->candidates($lodgeId, $e)->whereKey($lineId)->firstOrFail();
            $match = Reconciliation::query()->create(['lodge_id' => $lodgeId, 'statement_entry_id' => $e->id, 'line_id' => $line->id, 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
            Audit::record('treasury.reconciled', VerifiedSession::user()->id, $lodgeId, metadata: ['reconciliation_id' => $match->id]);

            return $match;
        }, 3);
    }

    public function approve(int $lodgeId, int $entryId, int $categoryId, string $key): Reconciliation
    {
        $source = $this->access->guard($lodgeId, 'create');
        $this->access->guard($lodgeId, 'update');

        return DB::transaction(function () use ($source, $lodgeId, $entryId, $categoryId, $key): Reconciliation {
            $this->access->lock($lodgeId, $source->obedience_id, 'create');
            $this->access->guard($lodgeId, 'update');
            $e = StatementEntry::query()->where('lodge_id', $lodgeId)->whereKey($entryId)->firstOrFail();
            $existing = $e->reconciliations()->whereNull('ended_at')->first();
            if ($existing) {
                return $existing;
            }
            // A possible existing posting must be inspected instead of counting the bank receipt twice.
            abort_if($this->candidates($lodgeId, $e)->exists(), 409, 'Esiste una registrazione candidata: riconcilia quella, senza creare un secondo incasso.');
            $cents = Money::cents($e->amount, true);
            $op = app(Ledger::class)->post($lodgeId, ['kind' => $cents > 0 ? 'income' : 'expense', 'account_id' => $e->account_id, 'amount' => Money::decimal(abs($cents)), 'booked_on' => $e->booked_on->toDateString(), 'description' => 'Da estratto conto: '.$e->description, 'category_id' => $categoryId], $key);
            $line = $op->lines()->where('account_id', $e->account_id)->firstOrFail();

            return $this->match($lodgeId, $e->id, $line->id);
        }, 3);
    }

    public function release(int $lodgeId, int $id, string $reason): void
    {
        $source = $this->access->guard($lodgeId, 'reverse');
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        DB::transaction(function () use ($source, $lodgeId, $id, $reason): void {
            $this->access->lock($lodgeId, $source->obedience_id, 'reverse');
            $r = Reconciliation::query()->where('lodge_id', $lodgeId)->whereKey($id)->firstOrFail();
            if ($r->ended_at !== null) {
                return;
            }$r->update(['ended_at' => now('UTC'), 'released_by' => VerifiedSession::user()->id, 'release_reason' => $reason]);
            Audit::record('treasury.reconciliation.released', VerifiedSession::user()->id, $lodgeId, metadata: ['reconciliation_id' => $id]);
        }, 3);
    }

    /** @return array{batch:ImportBatch,token:string} */
    public function preview(int $lodgeId, int $accountId, string $csv): array
    {
        $this->access->guard($lodgeId, 'update');
        $a = $this->bank($lodgeId, $accountId);
        if (strlen($csv) > 262144 || ! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw ValidationException::withMessages(['csv' => 'CSV UTF-8 richiesto, massimo 256 KiB.']);
        }
        $f = fopen('php://temp', 'r+');
        if ($f === false) {
            throw new \RuntimeException('CSV non leggibile.');
        }
        $rows = [];
        $errors = [];
        $seen = [];
        try {
            fwrite($f, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
            rewind($f);
            if (fgetcsv($f, 0, ';', '"', '') !== ['booked_on', 'amount', 'description', 'reference']) {
                throw ValidationException::withMessages(['csv' => 'Intestazione richiesta: booked_on;amount;description;reference']);
            }
            while (($line = fgetcsv($f, 0, ';', '"', '')) !== false) {
                if ($line === [null]) {
                    continue;
                }if (count($rows) >= 200 || count($line) !== 4) {
                    throw ValidationException::withMessages(['csv' => 'Massimo 200 righe, quattro colonne per riga.']);
                }$n = count($rows) + 2;
                $input = array_combine(['booked_on', 'amount', 'description', 'reference'], $line);
                $row = ['input' => $input, 'duplicate' => false, 'similar' => false];
                try {
                    $v = $this->row($input);
                    if ($v['booked_on'] < $a->opened_on->toDateString()) {
                        throw ValidationException::withMessages(['booked_on' => 'Data precedente all’apertura.']);
                    }$row['duplicate'] = $this->duplicate($lodgeId, $accountId, $v) !== null || isset($seen['ref:'.$v['reference_digest']]);
                    $row['similar'] = ! $row['duplicate'] && (isset($seen['fp:'.$v['fingerprint']]) || StatementEntry::query()->where('lodge_id', $lodgeId)->where('account_id', $accountId)->where('fingerprint', $v['fingerprint'])->exists());
                    $seen['ref:'.$v['reference_digest']] = true;
                    $seen['fp:'.$v['fingerprint']] = true;
                } catch (ValidationException $e) {
                    $errors[$n] = $e->errors();
                }$rows[] = $row;
            }
        } finally {
            fclose($f);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['csv' => 'Nessuna riga nel file.']);
        }
        $token = bin2hex(random_bytes(32));
        $batch = ImportBatch::query()->create(['lodge_id' => $lodgeId, 'account_id' => $accountId, 'actor_id' => VerifiedSession::user()->id, 'token_digest' => hash('sha256', $token), 'session_digest' => $this->binding(), 'payload' => ['rows' => $rows, 'errors' => $errors], 'expires_at' => now('UTC')->addMinutes(15), 'created_at' => now('UTC')]);

        return compact('batch', 'token');
    }

    public function batch(int $lodgeId, int $id, string $token): ImportBatch
    {
        $this->access->guard($lodgeId, 'update');
        $batch = ImportBatch::query()->where('lodge_id', $lodgeId)->where('actor_id', VerifiedSession::user()->id)->whereKey($id)->firstOrFail();
        abort_unless(hash_equals($batch->token_digest, hash('sha256', $token)) && hash_equals($batch->session_digest, $this->binding()), 403);
        abort_if($batch->expires_at->lessThan(now('UTC')), 410, 'Anteprima scaduta.');

        return $batch;
    }

    public function confirm(int $lodgeId, int $id, string $token): int
    {
        $source = $this->access->guard($lodgeId, 'update');

        return DB::transaction(function () use ($source, $lodgeId, $id, $token): int {
            $this->access->lock($lodgeId, $source->obedience_id, 'update');
            $batch = $this->batch($lodgeId, $id, $token);
            if ($batch->confirmed_at !== null) {
                return $batch->created_count;
            }
            abort_if($batch->payload['errors'] !== [], 422, 'Correggi tutti gli errori prima della conferma.');
            $count = 0;
            foreach ($batch->payload['rows'] as $item) {
                $row = $this->row($item['input']);
                $existing = $this->duplicate($lodgeId, $batch->account_id, $row);
                if ($existing) {
                    abort_unless($existing->fingerprint === $row['fingerprint'], 409, 'Un riferimento bancario ha già dati differenti.');

                    continue;
                }$this->entry($lodgeId, $batch->account_id, $item['input']);
                $count++;
            }
            $batch->update(['confirmed_at' => now('UTC'), 'created_count' => $count]);
            Audit::record('treasury.import.confirmed', VerifiedSession::user()->id, $lodgeId, metadata: ['batch_id' => $id, 'count' => $count]);

            return $count;
        }, 3);
    }

    private function binding(): string
    {
        $nonce = session('treasury.import_nonce');
        if (! is_string($nonce)) {
            $nonce = bin2hex(random_bytes(32));
            session()->put('treasury.import_nonce', $nonce);
        }

        return hash('sha256', $nonce);
    }
}
