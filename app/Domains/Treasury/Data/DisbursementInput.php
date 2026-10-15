<?php

namespace App\Domains\Treasury\Data;

final readonly class DisbursementInput
{
    public function __construct(public int $lodgeId, public int $obligationId, public int $recipientId, public string $recipientName, public ?string $recipientIban, public string $accountReference, public int $amountCents, public string $paidOn, public string $idempotencyKey) {}
}
