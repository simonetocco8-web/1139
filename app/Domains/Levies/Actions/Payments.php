<?php

namespace App\Domains\Levies\Actions;

use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Levies\Models\Payment;
use App\Domains\Treasury\Adapters\TreasuryUnavailable;
use App\Domains\Treasury\Contracts\DisbursementGateway;
use App\Domains\Treasury\Data\DisbursementInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Payments
{
    public function __construct(private Access $access, private DisbursementGateway $gateway) {}

    public function pay(int $lodgeId, int $obligationId, string $amount, string $paidOn, string $account, string $key): Payment
    {
        $source = $this->access->guard($lodgeId, 'create');
        Validator::make(['amount' => $amount, 'paid_on' => $paidOn, 'account' => $account, 'key' => $key], ['amount' => ['required', 'string'], 'paid_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'], 'account' => ['required', 'string', 'max:64'], 'key' => ['required', 'regex:/^[a-f0-9]{32,64}$/D']])->validate();
        $cents = Money::cents($amount);
        if ($cents === 0) {
            throw ValidationException::withMessages(['amount' => 'Il pagamento deve essere positivo.']);
        }
        $fingerprint = hash('sha256', json_encode([$obligationId, $cents, $paidOn, $account], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($lodgeId, $obligationId, $cents, $paidOn, $account, $key, $fingerprint, $source): Payment {
            $this->access->lock($lodgeId, $source->obedience_id);
            $this->access->guard($lodgeId, 'create');
            $due = Obligation::query()->where('lodge_id', $lodgeId)->whereKey($obligationId)->lockForUpdate()->firstOrFail();
            $previous = Payment::query()->where('lodge_id', $lodgeId)->where('idempotency_key', $key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, 'Chiave già utilizzata per dati differenti.');

                return $previous;
            }
            if ($paidOn < $due->period_start->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => 'La data precede la maturazione dell’obbligo.']);
            }
            if ($cents > Balances::of($due)['residual']) {
                throw ValidationException::withMessages(['amount' => 'Il pagamento supera il residuo.']);
            }
            if (! $this->gateway->available()) {
                throw new TreasuryUnavailable('Tesoreria non disponibile: nessun pagamento o movimento è stato registrato.');
            }
            $accounts = $this->gateway->accounts($lodgeId);
            abort_unless(collect($accounts)->contains(fn (array $a) => $a['id'] === $account), 422, 'Conto non disponibile per questa loggia.');
            $posting = $this->gateway->post(new DisbursementInput($lodgeId, $due->id, $due->recipient_id, $due->recipient_name, $due->recipient_iban, $account, $cents, $paidOn, $key), DB::connection());
            if ($posting->operationReference === '' || strlen($posting->operationReference) > 64 || $posting->accountLabel === '' || mb_strlen($posting->accountLabel) > 160) {
                throw new \LogicException('Riferimento Tesoreria non valido.');
            }
            $payment = Payment::query()->create(['lodge_id' => $lodgeId, 'obligation_id' => $due->id, 'amount' => Money::decimal($cents), 'paid_on' => $paidOn, 'account_reference' => $account, 'account_label' => $posting->accountLabel, 'operation_reference' => $posting->operationReference, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
            Audit::record('levy.payment.posted', VerifiedSession::user()->id, $lodgeId, metadata: ['obligation_id' => $due->id, 'payment_id' => $payment->id]);

            return $payment;
        }, 3);
    }
}
