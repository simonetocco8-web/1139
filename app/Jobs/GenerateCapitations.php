<?php

namespace App\Jobs;

use App\Domains\Capitations\Actions\Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateCapitations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $lodgeId, public readonly string $through) {}

    public function handle(Generator $generator): void
    {
        $generator->run($this->lodgeId, $this->through);
    }
}
