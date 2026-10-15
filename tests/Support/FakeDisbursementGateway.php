<?php

namespace Tests\Support;

use App\Domains\Treasury\Contracts\DisbursementGateway;
use App\Domains\Treasury\Data\DisbursementInput;
use App\Domains\Treasury\Data\PostingReference;
use Illuminate\Database\ConnectionInterface;

final class FakeDisbursementGateway implements DisbursementGateway
{
    public bool $failAfterWrite = false;

    public bool $invalidReference = false;

    public function available(): bool
    {
        return app()->environment('testing');
    }

    public function accounts(int $lodgeId): array
    {
        return [['id' => 'cash-'.$lodgeId, 'label' => 'Cassa Fittizia di Test']];
    }

    public function post(DisbursementInput $input, ConnectionInterface $connection): PostingReference
    {
        if (! app()->environment('testing') || $connection->transactionLevel() < 1) {
            throw new \RuntimeException('Solo test transazionali.');
        }
        $id = $connection->table('identity_events')->insertGetId(['event' => 'test.treasury.disbursed', 'lodge_id' => $input->lodgeId, 'metadata' => json_encode(['key' => $input->idempotencyKey, 'amount' => $input->amountCents, 'account' => $input->accountReference, 'recipient_id' => $input->recipientId, 'recipient_name' => $input->recipientName, 'recipient_iban' => $input->recipientIban], JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        if ($this->failAfterWrite) {
            throw new \RuntimeException('Errore dopo scrittura di test.');
        }

        return new PostingReference($this->invalidReference ? '' : 'test-outgoing-'.$id, 'Cassa Fittizia di Test');
    }
}
