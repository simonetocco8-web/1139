<?php

namespace App\Domains\Identity\Delivery;

final class FakeTransport implements CodeTransport
{
    /** @var list<array{channel:string, destination:string, subject:string, body:string}> */
    public array $messages = [];

    public bool $fail = false;

    public function available(string $channel): bool
    {
        return app()->environment('testing');
    }

    public function send(string $channel, string $destination, string $subject, string $body): void
    {
        if (! $this->available($channel) || $this->fail) {
            throw new DeliveryUnavailable('Canale non disponibile.');
        }
        $this->messages[] = compact('channel', 'destination', 'subject', 'body');
    }
}
