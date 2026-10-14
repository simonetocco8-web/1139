<?php

namespace App\Domains\Treasury\Data;

final readonly class ReceiptInput
{
    public function __construct(public int $lodgeId, public int $personId, public string $accountReference, public int $amountCents, public string $receivedOn, public string $idempotencyKey) {}
}
