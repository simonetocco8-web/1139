<?php

namespace App\Domains\Treasury\Data;

final readonly class PostingReference
{
    public function __construct(public string $operationReference, public string $accountLabel) {}
}
