<?php

namespace App\Domains\Treasury\Adapters;

use App\Domains\Treasury\Contracts\ReceiptGateway;
use App\Domains\Treasury\Data\PostingReference;
use App\Domains\Treasury\Data\ReceiptInput;
use Illuminate\Database\ConnectionInterface;

final class UnavailableReceiptGateway implements ReceiptGateway
{
    public function available(): bool
    {
        return false;
    }

    public function accounts(int $lodgeId): array
    {
        return [];
    }

    public function post(ReceiptInput $input, ConnectionInterface $connection): PostingReference
    {
        throw new TreasuryUnavailable('Tesoreria non disponibile: nessun incasso o movimento è stato registrato.');
    }
}
