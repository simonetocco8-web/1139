<?php

namespace App\Domains\Treasury\Adapters;

use App\Domains\Levies\Models\Obligation;
use App\Domains\Treasury\Actions\Access;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Contracts\DisbursementGateway;
use App\Domains\Treasury\Data\DisbursementInput;
use App\Domains\Treasury\Data\PostingReference;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class LedgerDisbursementGateway implements DisbursementGateway
{
    public function available(): bool
    {
        return true;
    }

    public function accounts(int $lodgeId): array
    {
        return app(LedgerReceiptGateway::class)->accounts($lodgeId);
    }

    public function post(DisbursementInput $input, ConnectionInterface $connection): PostingReference
    {
        if ($connection !== DB::connection() || $connection->transactionLevel() < 1) {
            throw new \LogicException('È richiesta la transazione condivisa.');
        }
        abort_unless(ctype_digit($input->accountReference), 422);
        $due = Obligation::query()->where('lodge_id', $input->lodgeId)->whereKey($input->obligationId)->firstOrFail();
        abort_unless($due->recipient_id === $input->recipientId, 422);
        $op = app(Ledger::class)->source($input->lodgeId, 'levy', (int) $input->accountReference, $input->amountCents, $input->paidOn, $input->idempotencyKey, null, $input->recipientId, 'Pagamento canone · '.$input->recipientName);
        $a = app(Access::class)->account($input->lodgeId, (int) $input->accountReference);

        return new PostingReference($op->public_id, $a->name);
    }
}
