<?php

namespace App\Console\Commands;

use App\Domains\Capitations\Actions\Generator;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class GenerateCapitations extends Command
{
    protected $signature = 'capitation:generate {--queued : Accoda job su database} {--lodge= : ID loggia facoltativo} {--through= : Data limite AAAA-MM-GG, mai futura}';

    protected $description = 'Genera rate maturate in modo idempotente, senza incassi o movimenti';

    public function handle(Generator $generator): int
    {
        $through = $this->option('through') ?: now()->toDateString();
        if (! is_string($through)) {
            $this->error('Data limite non valida.');

            return self::FAILURE;
        }
        Validator::make(['through' => $through], ['through' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])->validate();
        $id = $this->option('lodge');
        $query = Lodge::query()->where('is_active', true)->whereHas('obedience', fn ($q) => $q->where('is_active', true));
        if ($id !== null) {
            if (! is_string($id) || ! ctype_digit($id)) {
                $this->error('ID loggia non valido.');

                return self::FAILURE;
            }$query->whereKey((int) $id);
        }
        $count = 0;
        foreach ($query->orderBy('id')->cursor() as $lodge) {
            if ($this->option('queued')) {
                \App\Jobs\GenerateCapitations::dispatch($lodge->id, $through)->onConnection('database');
            } else {
                $count += $generator->run($lodge->id, $through);
            }
        }
        if ($this->option('queued')) {
            $this->info('Generazione accodata per le logge selezionate. Nessun movimento finanziario.');

            return self::SUCCESS;
        }
        $this->info('Rate create: '.$count.'. Nessun incasso registrato.');

        return self::SUCCESS;
    }
}
