<?php

namespace App\Domains\Treasury\Contracts;

use App\Domains\Treasury\Data\DisbursementInput;
use App\Domains\Treasury\Data\PostingReference;
use Illuminate\Database\ConnectionInterface;

interface DisbursementGateway
{
    public function available(): bool;

    /** @return list<array{id:string,label:string}> */
    public function accounts(int $lodgeId): array;

    // Actual bank/cash outgoing, same DB connection and transaction, scoped account,
    // EUR, period controls and idempotency. No autonomous commit or remote side effects.
    public function post(DisbursementInput $input, ConnectionInterface $connection): PostingReference;
}
