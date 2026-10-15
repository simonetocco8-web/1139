<?php

namespace App\Console\Commands;

use App\Domains\Levies\Actions\Generator;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class GenerateLevies extends Command
{
    protected $signature = 'levy:generate {--queued : Accoda job su database} {--lodge=} {--through=}';

    protected $description = 'Genera obblighi canoni idempotenti senza movimenti finanziari';

    public function handle(Generator $generator): int
    {
        $date = $this->option('through') ?? now()->toDateString();
        if (! is_string($date)) {
            $this->error('Data non valida.');

            return self::FAILURE;
        }
        Validator::make(['through' => $date, 'lodge' => $this->option('lodge')], ['through' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2000-01-01'], 'lodge' => ['nullable', 'integer', 'min:1']])->validate();
        $q = Lodge::query()->where('is_active', true)->whereHas('obedience', fn ($q) => $q->where('is_active', true));
        if ($this->option('lodge')) {
            $q->whereKey((int) $this->option('lodge'));
        }
        $count = 0;
        foreach ($q->cursor() as $lodge) {
            if ($this->option('queued')) {
                \App\Jobs\GenerateLevies::dispatch($lodge->id, $date)->onConnection('database');
            } else {
                $count += $generator->run($lodge->id, $date);
            }
        }
        if ($this->option('queued')) {
            $this->info('Generazione accodata per le logge selezionate. Nessun movimento finanziario.');

            return self::SUCCESS;
        }
        $this->info('Obblighi generati: '.$count.'. Nessuna uscita finanziaria.');

        return self::SUCCESS;
    }
}
