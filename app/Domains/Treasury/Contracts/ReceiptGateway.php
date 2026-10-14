<?php

namespace App\Domains\Treasury\Contracts;

use App\Domains\Treasury\Data\PostingReference;
use App\Domains\Treasury\Data\ReceiptInput;
use Illuminate\Database\ConnectionInterface;

interface ReceiptGateway
{
    public function available(): bool;

    /** @return list<array{id:string,label:string}> */
    public function accounts(int $lodgeId): array;

    // MUST write the real cash/bank operation on the caller's DB connection/transaction,
    // validate the account tenant, and honor the key. No irreversible remote side effects.
    public function post(ReceiptInput $input, ConnectionInterface $connection): PostingReference;
}
