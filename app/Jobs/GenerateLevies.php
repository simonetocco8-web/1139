<?php

namespace App\Jobs;

use App\Domains\Levies\Actions\Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateLevies implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $lodgeId, public string $through) {}

    public function handle(Generator $generator): void
    {
        $generator->run($this->lodgeId, $this->through);
    }
}
