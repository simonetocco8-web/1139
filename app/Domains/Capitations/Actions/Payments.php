<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Models\Allocation;
use App\Domains\Capitations\Models\AllocationBatch;
use App\Domains\Capitations\Models\Correction;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Adapters\TreasuryUnavailable;
use App\Domains\Treasury\Contracts\ReceiptGateway;
use App\Domains\Treasury\Data\ReceiptInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Payments
{
    public function __construct(private Access $access, private ReceiptGateway $gateway) {}

    /** @param array<int,string> $amounts */
    public function receive(int $lodgeId, int $personId, string $amount, string $date, string $account, string $key, array $amounts = []): Receipt
    {
        $source = $this->access->guard($lodgeId, 'create');
        $this->key($key);
        $cents = Money::cents($amount);
        if ($cents <= 0) {
            $this->invalid('Importo incassato positivo richiesto.');
        }
        Validator::make(['received_on' => $date], ['received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])->validate();
        $map = $this->map($amounts);
        $fingerprint = hash('sha256', json_encode([$personId, $cents, $date, $account, $map], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($lodgeId, $personId, $cents, $date, $account, $key, $map, $fingerprint, $source): Receipt {
            $this->lock($lodgeId, 'create', $source->obedience_id);
            $this->access->person($lodgeId, $personId);
            $existing = Receipt::query()->where('lodge_id', $lodgeId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409);

                return $existing;
            }
            if (! $this->gateway->available()) {
                throw new TreasuryUnavailable('Tesoreria non disponibile: nessun incasso o movimento è stato registrato.');
            }
            abort_unless(collect($this->gateway->accounts($lodgeId))->contains(fn ($a) => $a['id'] === $account), 422, 'Conto/cassa non disponibile per questa loggia.');
            // Validate the complete allocation before calling the shared posting adapter.
            $this->validateMap($lodgeId, $personId, $map, $cents);
            $posting = $this->gateway->post(new ReceiptInput($lodgeId, $personId, $account, $cents, $date, $key), DB::connection());
            abort_unless($posting->operationReference !== '' && strlen($posting->operationReference) <= 64 && $posting->accountLabel !== '' && mb_strlen($posting->accountLabel) <= 160, 500);
            $receipt = Receipt::query()->create(['lodge_id' => $lodgeId, 'person_id' => $personId, 'amount' => Money::decimal($cents), 'received_on' => $date, 'account_reference' => $account, 'account_label' => $posting->accountLabel, 'operation_reference' => $posting->operationReference, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now('UTC')]);
            app(Ledger::class)->link($lodgeId, $posting->operationReference, $receipt->id, null);
            if ($map !== []) {
                $this->allocate($lodgeId, $personId, $receipt->id, hash('sha256', $key.'|initial'), $this->decimals($map));
            }
            Audit::record('capitation.received', VerifiedSession::user()->id, $lodgeId, metadata: ['receipt_id' => $receipt->id, 'person_id' => $personId]);

            return $receipt;
        }, 3);
    }

    /** @param array<int,string> $amounts */
    public function allocate(int $lodgeId, int $personId, int $receiptId, string $key, array $amounts): void
    {
        $source = $this->access->guard($lodgeId, 'update');
        $this->key($key);
        $map = $this->map($amounts);
        if ($map === []) {
            $this->invalid('Indica almeno una rata e un importo positivo.');
        }
        $fingerprint = hash('sha256', json_encode([$receiptId, $personId, $map], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($lodgeId, $personId, $receiptId, $key, $map, $fingerprint, $source): void {
            $this->lock($lodgeId, 'update', $source->obedience_id);
            $this->access->person($lodgeId, $personId);
            $receipt = Receipt::query()->where('lodge_id', $lodgeId)->where('person_id', $personId)->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            abort_if($receipt->isVoided(), 409, 'Incasso già stornato.');
            $existing = AllocationBatch::query()->where('lodge_id', $lodgeId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409);

                return;
            }
            $this->validateMap($lodgeId, $personId, $map, Balances::credit($receipt));
            AllocationBatch::query()->create(['lodge_id' => $lodgeId, 'receipt_id' => $receiptId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now('UTC')]);
            foreach ($map as $id => $cents) {
                Allocation::query()->create(['lodge_id' => $lodgeId, 'person_id' => $personId, 'receipt_id' => $receiptId, 'installment_id' => $id, 'amount' => Money::decimal($cents), 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
            }
            Audit::record('capitation.allocated', VerifiedSession::user()->id, $lodgeId, metadata: ['receipt_id' => $receiptId]);
        }, 3);
    }

    public function correct(int $lodgeId, int $id, string $amount, string $reason, string $key): void
    {
        $source = $this->access->guard($lodgeId, 'reverse');
        $this->key($key);
        $delta = Money::cents($amount, true);
        if ($delta === 0) {
            $this->invalid('Rettifica diversa da zero richiesta.');
        }
        $this->reason($reason);
        $fp = hash('sha256', json_encode([$id, $delta, $reason], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($lodgeId, $id, $delta, $reason, $key, $fp, $source): void {
            $this->lock($lodgeId, 'reverse', $source->obedience_id);
            $due = Installment::query()->where('lodge_id', $lodgeId)->whereKey($id)->lockForUpdate()->firstOrFail();
            $existing = Correction::query()->where('lodge_id', $lodgeId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->fingerprint, $fp), 409);

                return;
            }
            $balance = Balances::installment($due);
            if ($balance['due'] + $delta < 0 || $balance['residual'] + $delta < 0 || $balance['due'] + $delta > 999999999999999) {
                $this->invalid('Rettifica oltre i limiti: liberare prima le somme già imputate.');
            }
            Correction::query()->create(['lodge_id' => $lodgeId, 'installment_id' => $id, 'amount' => Money::decimal($delta), 'reason' => $reason, 'idempotency_key' => $key, 'fingerprint' => $fp, 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
            Audit::record('capitation.corrected', VerifiedSession::user()->id, $lodgeId, metadata: ['installment_id' => $id]);
        }, 3);
    }

    public function reverse(int $lodgeId, int $id, string $reason): void
    {
        $source = $this->access->guard($lodgeId, 'reverse');
        $this->reason($reason);
        DB::transaction(function () use ($lodgeId, $id, $reason, $source): void {
            $this->lock($lodgeId, 'reverse', $source->obedience_id);
            $a = Allocation::query()->where('lodge_id', $lodgeId)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($a->reverses_id !== null, 409);
            if (Allocation::query()->where('reverses_id', $a->id)->exists()) {
                return;
            }
            Allocation::query()->create(['lodge_id' => $lodgeId, 'person_id' => $a->person_id, 'receipt_id' => $a->receipt_id, 'installment_id' => $a->installment_id, 'amount' => Money::decimal(-Money::cents($a->amount)), 'reverses_id' => $a->id, 'reason' => $reason, 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
            Audit::record('capitation.allocation.reversed', VerifiedSession::user()->id, $lodgeId, metadata: ['allocation_id' => $id]);
        }, 3);
    }

    private function lock(int $lodgeId, string $permission, int $obedienceId): void
    {
        app(\App\Domains\Levies\Actions\Access::class)->lock($lodgeId, $obedienceId);
        $this->access->guard($lodgeId, $permission);
    }

    /** @param array<array-key,mixed> $amounts
     * @return array<int,int> */
    private function map(array $amounts): array
    {
        if (count($amounts) > 50) {
            $this->invalid('Massimo 50 rate per imputazione.');
        }$map = [];
        foreach ($amounts as $id => $value) {
            if (! is_int($id) || $id < 1 || ! is_string($value)) {
                $this->invalid('Imputazione non valida.');
            }if (trim($value) === '') {
                continue;
            }$c = Money::cents($value);
            if ($c > 0) {
                $map[$id] = $c;
            }
        }
        ksort($map);

        return $map;
    }

    /** @param array<int,int> $map */
    private function validateMap(int $lodgeId, int $personId, array $map, int $available): void
    {
        $total = 0;
        foreach ($map as $id => $c) {
            $due = Installment::query()->where('lodge_id', $lodgeId)->where('person_id', $personId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($c > Balances::installment($due)['residual']) {
                $this->invalid('Importo maggiore del residuo della rata.');
            }if ($c > $available - $total) {
                $this->invalid('Credito o importo incassato insufficiente.');
            }$total += $c;
        }
    }

    /** @param array<int,int> $map
     * @return array<int,string> */
    private function decimals(array $map): array
    {
        return array_map(fn ($v) => Money::decimal($v), $map);
    }

    private function key(string $key): void
    {
        Validator::make(['key' => $key], ['key' => ['required', 'regex:/^[a-f0-9]{32,64}$/D']])->validate();
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['amount' => $message]);
    }
}
