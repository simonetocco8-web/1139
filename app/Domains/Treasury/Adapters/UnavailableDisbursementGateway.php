<?php

namespace App\Domains\Treasury\Adapters;

use App\Domains\Treasury\Contracts\DisbursementGateway;
use App\Domains\Treasury\Data\DisbursementInput;
use App\Domains\Treasury\Data\PostingReference;
use Illuminate\Database\ConnectionInterface;

final class UnavailableDisbursementGateway implements DisbursementGateway
{
    public function available(): bool
    {
        return false;
    }

    public function accounts(int $lodgeId): array
    {
        return [];
    }

    public function post(DisbursementInput $input, ConnectionInterface $connection): PostingReference
    {
        throw new TreasuryUnavailable('Tesoreria non disponibile: nessun pagamento o movimento è stato registrato.');
    }
}
