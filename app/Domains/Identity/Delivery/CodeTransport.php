<?php

namespace App\Domains\Identity\Delivery;

interface CodeTransport
{
    public function available(string $channel): bool;

    public function send(string $channel, string $destination, string $subject, string $body): void;
}
