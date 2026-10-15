<?php

namespace App\Domains\Treasury\Adapters;

use App\Domains\Treasury\Actions\Access;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Contracts\ReceiptGateway;
use App\Domains\Treasury\Data\PostingReference;
use App\Domains\Treasury\Data\ReceiptInput;
use App\Domains\Treasury\Models\Account;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class LedgerReceiptGateway implements ReceiptGateway
{
    public function available(): bool
    {
        return true;
    }

    public function accounts(int $lodgeId): array
    {
        app(Access::class)->guard($lodgeId, 'create');

        return array_values(Account::query()->where('lodge_id', $lodgeId)->orderBy('name')->get()->map(fn (Account $a) => ['id' => (string) $a->id, 'label' => $a->name])->all());
    }

    public function post(ReceiptInput $input, ConnectionInterface $connection): PostingReference
    {
        if ($connection !== DB::connection() || $connection->transactionLevel() < 1) {
            throw new \LogicException('È richiesta la transazione condivisa.');
        }
        abort_unless(ctype_digit($input->accountReference), 422);
        $op = app(Ledger::class)->source($input->lodgeId, 'capitation', (int) $input->accountReference, $input->amountCents, $input->receivedOn, $input->idempotencyKey, $input->personId, null, 'Incasso capitazione');
        $a = app(Access::class)->account($input->lodgeId, (int) $input->accountReference);

        return new PostingReference($op->public_id, $a->name);
    }
}
