<?php

namespace App\Domains\Treasury\Actions;

use App\Domains\Capitations\Actions\Payments;
use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Structure;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Line;
use App\Domains\Treasury\Models\Operation;
use App\Domains\Treasury\Models\PaymentVoid;
use App\Domains\Treasury\Models\ReceiptVoid;
use App\Domains\Treasury\Models\Reconciliation;
use App\Domains\Treasury\Models\SourceLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Ledger
{
    public function __construct(private Access $access) {}

    /** @param array<string,mixed> $input */
    public function account(int $lodgeId, array $input, string $key): Account
    {
        $source = $this->access->guard($lodgeId, 'administration');
        $this->key($key);
        $v = Validator::make($input, ['name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['bank', 'cash'])], 'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'], 'currency' => ['required', Rule::in(['EUR'])], 'opening_amount' => ['required', 'string'], 'opened_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today']])->validate();
        $opening = Money::cents($v['opening_amount'], true);
        $v['opening_amount'] = Money::decimal($opening);
        $v['iban'] = $v['iban'] ?? null;
        if ($v['type'] === 'cash' && ($opening < 0 || $v['iban'] !== null)) {
            throw ValidationException::withMessages(['opening_amount' => 'Cassa contanti: saldo iniziale non negativo e nessun IBAN.']);
        }
        ksort($v);
        $fp = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($source, $lodgeId, $key, $v, $fp, $opening): Account {
            $this->access->lock($lodgeId, $source->obedience_id, 'administration');
            $old = Account::query()->where('lodge_id', $lodgeId)->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fp), 409);

                return $old;
            }
            abort_if(Account::query()->where('lodge_id', $lodgeId)->where('name', $v['name'])->exists(), 409, 'Nome conto già utilizzato.');
            $a = Account::query()->create($v + ['lodge_id' => $lodgeId, 'idempotency_key' => $key, 'fingerprint' => $fp, 'created_at' => now('UTC')]);
            $this->record($lodgeId, 'opening', $v['opened_on'], 'Saldo iniziale · '.$a->name, null, null, null, 'opening:'.$a->id, [['account_id' => $a->id, 'bucket' => 'financial', 'cents' => $opening], ['account_id' => null, 'bucket' => 'equity', 'cents' => -$opening]]);
            Audit::record('treasury.account.created', VerifiedSession::user()->id, $lodgeId, metadata: ['account_id' => $a->id]);

            return $a;
        }, 3);
    }

    public function category(int $lodgeId, string $name, string $type): Category
    {
        $source = $this->access->guard($lodgeId, 'update');
        Validator::make(compact('name', 'type'), ['name' => ['required', 'string', 'max:80'], 'type' => ['required', Rule::in(['income', 'expense'])]])->validate();

        return DB::transaction(function () use ($source, $lodgeId, $name, $type): Category {
            $this->access->lock($lodgeId, $source->obedience_id, 'update');
            $c = Category::query()->firstOrCreate(['lodge_id' => $lodgeId, 'name' => $name, 'type' => $type]);
            Audit::record('treasury.category.created', VerifiedSession::user()->id, $lodgeId, metadata: ['category_id' => $c->id]);

            return $c;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function post(int $lodgeId, array $input, string $key): Operation
    {
        $v = Validator::make($input, ['kind' => ['required', Rule::in(['income', 'expense', 'transfer', 'adjustment'])], 'account_id' => ['required', 'integer'], 'target_id' => ['required_if:kind,transfer', 'nullable', 'integer'], 'amount' => ['required', 'string'], 'booked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2000-01-01'], 'description' => ['required', 'string', 'min:5', 'max:500'], 'category_id' => ['required_if:kind,income,expense', 'nullable', 'integer'], 'person_id' => ['nullable', 'integer'], 'structure_id' => ['nullable', 'integer']], ['category_id.required_if' => 'Seleziona una categoria per l’entrata o la spesa.', 'target_id.required_if' => 'Seleziona il conto di destinazione del giroconto.'])->validate();
        $permission = $v['kind'] === 'adjustment' ? 'reverse' : 'create';
        $source = $this->access->guard($lodgeId, $permission);
        $this->key($key);
        $amount = Money::cents($v['amount'], $v['kind'] === 'adjustment');
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => 'Importo diverso da zero richiesto.']);
        }

        return DB::transaction(function () use ($v, $source, $lodgeId, $key, $permission, $amount): Operation {
            $this->access->lock($lodgeId, $source->obedience_id, $permission);
            $a = $this->access->account($lodgeId, (int) $v['account_id']);
            $cat = null;
            if (in_array($v['kind'], ['income', 'expense'], true)) {
                $cat = Category::query()->where('lodge_id', $lodgeId)->whereKey($v['category_id'])->firstOrFail();
                if ($cat->type !== $v['kind']) {
                    throw ValidationException::withMessages(['category_id' => 'Seleziona una categoria compatibile con il tipo di operazione: entrata o spesa.']);
                }
            }
            $person = empty($v['person_id']) ? null : (int) $v['person_id'];
            $structure = empty($v['structure_id']) ? null : (int) $v['structure_id'];
            if ($person !== null) {
                Person::query()->where('lodge_id', $lodgeId)->whereKey($person)->firstOrFail();
            }if ($structure !== null) {
                Structure::query()->where('obedience_id', $source->obedience_id)->whereKey($structure)->firstOrFail();
            }
            if ($v['kind'] === 'transfer') {
                $b = $this->access->account($lodgeId, (int) ($v['target_id'] ?? 0));
                abort_if($a->id === $b->id, 422, 'Seleziona conti differenti.');
                $lines = [['account_id' => $a->id, 'bucket' => 'financial', 'cents' => -$amount], ['account_id' => $b->id, 'bucket' => 'financial', 'cents' => $amount]];
            } else {
                $delta = $v['kind'] === 'expense' ? -$amount : $amount;
                $lines = [['account_id' => $a->id, 'bucket' => 'financial', 'cents' => $delta], ['account_id' => null, 'bucket' => $v['kind'] === 'adjustment' ? 'equity' : $v['kind'], 'cents' => -$delta]];
            }

            return $this->record($lodgeId, $v['kind'], $v['booked_on'], $v['description'], $cat?->id, $person, $structure, 'manual:'.$key, $lines);
        }, 3);
    }

    // Called exclusively by the shared gateways inside the caller's transaction and locks.
    public function source(int $lodgeId, string $kind, int $accountId, int $amount, string $date, string $key, ?int $personId, ?int $structureId, string $description): Operation
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($kind, ['capitation', 'levy'], true) && $amount > 0, 500);
        $this->access->guard($lodgeId, 'create');
        $this->key($key);
        $account = $this->access->account($lodgeId, $accountId);
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today']])->validate();
        if ($personId !== null) {
            Person::query()->where('lodge_id', $lodgeId)->whereKey($personId)->firstOrFail();
        }
        if ($structureId !== null) {
            $lodge = $this->access->guard($lodgeId, 'create');
            Structure::query()->where('obedience_id', $lodge->obedience_id)->whereKey($structureId)->firstOrFail();
        }
        $delta = $kind === 'capitation' ? $amount : -$amount;

        return $this->record($lodgeId, $kind, $date, $description, null, $personId, $structureId, $kind.':'.$key, [['account_id' => $account->id, 'bucket' => 'financial', 'cents' => $delta], ['account_id' => null, 'bucket' => $delta > 0 ? 'income' : 'expense', 'cents' => -$delta]]);
    }

    public function reverse(int $lodgeId, int $operationId, string $date, string $reason, string $key): Operation
    {
        $source = $this->access->guard($lodgeId, 'reverse');
        $this->key($key);
        Validator::make(compact('date', 'reason'), ['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['required', 'string', 'min:5', 'max:500']])->validate();

        return DB::transaction(function () use ($source, $lodgeId, $operationId, $date, $reason, $key): Operation {
            $this->access->lock($lodgeId, $source->obedience_id, 'reverse');
            $op = Operation::query()->where('lodge_id', $lodgeId)->whereKey($operationId)->with('lines')->firstOrFail();
            abort_if($op->reverses_id !== null, 409, 'Non è consentito stornare uno storno.');
            abort_if($date < $op->booked_on->toDateString(), 422, 'Data precedente all’operazione originale.');
            $fpLines = array_values($op->lines->map(fn (Line $l) => ['account_id' => $l->account_id, 'bucket' => $l->bucket, 'cents' => -Money::cents($l->amount, true)])->all());
            $existing = $op->reversal;
            if ($existing) {
                abort_unless($existing->idempotency_key === 'reversal:'.$key && $existing->booked_on->toDateString() === $date && $existing->description === $reason, 409, 'Operazione già stornata.');

                return $existing;
            }
            abort_if(Reconciliation::query()->where('lodge_id', $lodgeId)->whereNull('ended_at')->whereIn('line_id', $op->lines->pluck('id'))->exists(), 409, 'Rilascia prima la riconciliazione bancaria.');
            $reversal = $this->record($lodgeId, 'reversal', $date, $reason, $op->category_id, $op->person_id, $op->structure_id, 'reversal:'.$key, $fpLines, $op->id);
            $link = SourceLink::query()->where('lodge_id', $lodgeId)->where('operation_id', $op->id)->first();
            if ($link?->receipt_id !== null) {
                $receipt = Receipt::query()->where('lodge_id', $lodgeId)->whereKey($link->receipt_id)->firstOrFail();
                foreach ($receipt->allocations()->whereNull('reverses_id')->get() as $a) {
                    app(Payments::class)->reverse($lodgeId, $a->id, $reason);
                }ReceiptVoid::query()->create(['lodge_id' => $lodgeId, 'receipt_id' => $receipt->id, 'operation_id' => $reversal->id]);
            }
            if ($link?->payment_id !== null) {
                PaymentVoid::query()->create(['lodge_id' => $lodgeId, 'payment_id' => $link->payment_id, 'operation_id' => $reversal->id]);
            }
            Audit::record('treasury.reversed', VerifiedSession::user()->id, $lodgeId, metadata: ['operation_id' => $op->id, 'reversal_id' => $reversal->id]);

            return $reversal;
        }, 3);
    }

    public function link(int $lodgeId, string $reference, ?int $receiptId, ?int $paymentId): void
    {
        $op = Operation::query()->where('lodge_id', $lodgeId)->where('public_id', $reference)->first();
        if ($op) {
            SourceLink::query()->create(['lodge_id' => $lodgeId, 'operation_id' => $op->id, 'receipt_id' => $receiptId, 'payment_id' => $paymentId]);
        }
    }

    /** @param list<array{account_id:int|null,bucket:string,cents:int}> $lines */
    private function record(int $lodgeId, string $kind, string $date, string $description, ?int $categoryId, ?int $personId, ?int $structureId, string $key, array $lines, ?int $reversesId = null): Operation
    {
        $sum = Money::add(...array_column($lines, 'cents'));
        if ($sum !== 0 || count($lines) < 2) {
            throw new \LogicException('Operazione non bilanciata.');
        }
        $fp = hash('sha256', json_encode([$kind, $date, $description, $categoryId, $personId, $structureId, $lines, $reversesId], JSON_THROW_ON_ERROR));
        $old = Operation::query()->where('lodge_id', $lodgeId)->where('idempotency_key', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->fingerprint, $fp), 409, 'Chiave riutilizzata con dati differenti.');

            return $old;
        }
        foreach ($lines as $line) {
            if (abs($line['cents']) > 999999999999999) {
                throw ValidationException::withMessages(['amount' => 'Importo oltre i limiti.']);
            }if ($line['account_id'] !== null) {
                $a = $this->access->account($lodgeId, $line['account_id']);
                abort_if($date < $a->opened_on->toDateString(), 422, 'Data precedente all’apertura del conto.');
            }
        }
        $op = Operation::query()->create(['lodge_id' => $lodgeId, 'obedience_id' => $this->access->guard($lodgeId)->obedience_id, 'public_id' => (string) Str::ulid(), 'kind' => $kind, 'booked_on' => $date, 'description' => $description, 'category_id' => $categoryId, 'person_id' => $personId, 'structure_id' => $structureId, 'reverses_id' => $reversesId, 'idempotency_key' => $key, 'fingerprint' => $fp, 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
        foreach ($lines as $line) {
            Line::query()->create(['lodge_id' => $lodgeId, 'operation_id' => $op->id, 'account_id' => $line['account_id'], 'bucket' => $line['bucket'], 'amount' => Money::decimal($line['cents'])]);
        }
        Balances::checkCash($lodgeId, array_values(array_filter(array_column($lines, 'account_id'), fn ($v) => $v !== null)));
        Audit::record('treasury.posted', VerifiedSession::user()->id, $lodgeId, metadata: ['operation_id' => $op->id, 'kind' => $kind]);

        return $op;
    }

    private function key(string $key): void
    {
        Validator::make(['key' => $key], ['key' => ['required', 'regex:/^[a-f0-9]{32,64}$/D']])->validate();
    }
}
