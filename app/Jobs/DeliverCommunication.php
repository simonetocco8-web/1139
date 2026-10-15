<?php

namespace App\Jobs;

use App\Domains\Notifications\Delivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverCommunication implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $outboxId) {}

    public function handle(Delivery $delivery): void
    {
        $delivery->send($this->outboxId);
    }
}
