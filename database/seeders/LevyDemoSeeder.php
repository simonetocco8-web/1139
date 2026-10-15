<?php

namespace Database\Seeders;

use App\Domains\Levies\Actions\Generator;
use App\Domains\Levies\Models\Condition;
use App\Domains\Levies\Models\Program;
use App\Domains\Membership\Models\Enrollment;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LevyDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Solo dati fittizi in locale/testing.');
        }
        DB::transaction(function (): void {
            $start = now()->startOfYear()->toDateString();
            $o = Obedience::query()->firstOrCreate(['code' => 'DEMO_LEVY'], ['name' => 'Obbedienza Fittizia Canoni', 'is_active' => true]);
            $r = Rite::query()->firstOrCreate(['code' => 'DEMO_LEVY'], ['name' => 'Rito Fittizio Canoni', 'is_active' => true]);
            $o->rites()->syncWithoutDetaching([$r->id]);
            $n = Structure::query()->firstOrCreate(['obedience_id' => $o->id, 'name' => 'Sovrastruttura Nazionale Fittizia Canoni'], ['level' => 'national', 'is_active' => true]);
            $p = Structure::query()->firstOrCreate(['obedience_id' => $o->id, 'name' => 'Sovrastruttura Provinciale Fittizia Canoni'], ['level' => 'provincial', 'parent_id' => $n->id, 'is_active' => true]);
            foreach ([1, 2] as $number) {
                $l = Lodge::query()->firstOrCreate(['obedience_id' => $o->id, 'number' => (string) $number], ['rite_id' => $r->id, 'name' => 'Loggia Dimostrativa Fittizia Canoni '.$number, 'is_active' => true]);
                foreach ([$n, $p] as $s) {
                    if (! $l->assignments()->where('structure_id', $s->id)->exists()) {
                        TerritorialAssignment::query()->create(['lodge_id' => $l->id, 'obedience_id' => $o->id, 'structure_id' => $s->id, 'level' => $s->level, 'structure_name' => $s->name, 'starts_at' => now()->startOfYear()->subDay()->utc()]);
                    }
                }
                foreach ([1, 2, 3] as $i) {
                    $person = Person::query()->firstOrCreate(['lodge_id' => $l->id, 'reference' => 'LEVY_DEMO_'.$i], ['first_name' => 'NomeCanoneFittizio'.$i, 'last_name' => 'CognomeCanoneFittizio'.$i, 'version' => 1]);
                    if (! $person->enrollments()->exists()) {
                        Enrollment::query()->create(['lodge_id' => $l->id, 'person_id' => $person->id, 'starts_on' => $start, 'status' => $i === 3 ? 'suspended' : 'active', 'administrative_exemption' => $i === 2, 'exemption_reason' => $i === 2 ? 'Esenzione Fittizia Canoni' : null, 'version' => 1]);
                    }
                }
            }
            foreach ([['NAZIONALE', 'national', 'quarterly', 'per_member', '15.50'], ['PROVINCIALE', 'provincial', 'monthly', 'fixed', '40.00']] as [$code,$level,$frequency,$basis,$amount]) {
                $program = Program::query()->firstOrCreate(['obedience_id' => $o->id, 'code' => $code], ['description' => 'Canone Fittizio '.$code, 'level' => $level, 'periodicity' => $frequency, 'starts_on' => $start, 'created_at' => now('UTC')]);
                if (! $program->conditions()->exists()) {
                    Condition::query()->create(['obedience_id' => $o->id, 'program_id' => $program->id, 'scope_key' => 'obedience', 'effective_on' => $start, 'basis' => $basis, 'amount' => $amount, 'count_criterion' => 'active_non_exempt_at_start', 'due_basis' => 'end', 'due_days' => 15, 'is_active' => true, 'reason' => 'Condizione dimostrativa completamente fittizia', 'created_at' => now('UTC')]);
                }
            }
            foreach (Lodge::query()->where('obedience_id', $o->id)->get() as $l) {
                app(Generator::class)->run($l->id, now()->toDateString());
            }
        });
    }
}
