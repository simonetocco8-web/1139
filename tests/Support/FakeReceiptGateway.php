<?php

namespace Tests\Support;

use App\Domains\Treasury\Contracts\ReceiptGateway;
use App\Domains\Treasury\Data\PostingReference;
use App\Domains\Treasury\Data\ReceiptInput;
use Illuminate\Database\ConnectionInterface;

final class FakeReceiptGateway implements ReceiptGateway
{
    public bool $fail = false;

    public bool $failAfterWrite = false;

    public function available(): bool
    {
        return app()->environment('testing');
    }

    public function accounts(int $lodgeId): array
    {
        return [['id' => 'cash-'.$lodgeId, 'label' => 'Cassa Fittizia di Test']];
    }

    public function post(ReceiptInput $input, ConnectionInterface $connection): PostingReference
    {
        if (! app()->environment('testing') || $connection->transactionLevel() < 1 || $this->fail) {
            throw new \RuntimeException('Adapter fittizio disponibile solo nei test e nella transazione corrente.');
        }
        $id = $connection->table('identity_events')->insertGetId(['event' => 'test.treasury.received', 'actor_id' => null, 'subject_user_id' => null, 'lodge_id' => $input->lodgeId, 'metadata' => json_encode(['key' => $input->idempotencyKey, 'amount' => $input->amountCents, 'account' => $input->accountReference], JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')->format('Y-m-d H:i:s.u')]);

        if ($this->failAfterWrite) {
            throw new \RuntimeException('Errore di test dopo scrittura.');
        }

        return new PostingReference('test-posting-'.$id, 'Cassa Fittizia di Test');
    }
}
