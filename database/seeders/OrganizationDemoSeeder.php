<?php

namespace Database\Seeders;

use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class OrganizationDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('I dati dimostrativi non sono consentiti in produzione.');
        }
        DB::transaction(function (): void {
            $rite = Rite::query()->firstOrCreate(['code' => 'DEMO_R1'], ['name' => 'Rito Fittizio Uno', 'is_active' => true]);
            $secondRite = Rite::query()->firstOrCreate(['code' => 'DEMO_R2'], ['name' => 'Rito Fittizio Due', 'is_active' => true]);
            $a = Obedience::query()->firstOrCreate(['code' => 'DEMO_A'], ['name' => 'Obbedienza Fittizia Alfa', 'is_active' => true]);
            $b = Obedience::query()->firstOrCreate(['code' => 'DEMO_B'], ['name' => 'Obbedienza Fittizia Beta', 'is_active' => true]);
            $a->rites()->syncWithoutDetaching([$rite->id, $secondRite->id]);
            $b->rites()->syncWithoutDetaching([$rite->id]);
            $national = Structure::query()->firstOrCreate(['obedience_id' => $a->id, 'name' => 'Struttura Demo Nazionale Alfa'], ['level' => 'national', 'is_active' => true]);
            $province = Structure::query()->firstOrCreate(['obedience_id' => $a->id, 'name' => 'Struttura Demo Provinciale Alfa'], ['level' => 'provincial', 'parent_id' => $national->id, 'is_active' => true]);
            $region = Structure::query()->firstOrCreate(['obedience_id' => $b->id, 'name' => 'Struttura Demo Regionale Beta'], ['level' => 'regional', 'is_active' => true]);
            foreach ([[$a, '17', 'Loggia Fittizia Alfa 001', [$national, $province]], [$a, '18', 'Loggia Fittizia Alfa 002', []], [$b, '17', 'Loggia Fittizia Beta 001', [$region]]] as [$owner, $number, $name, $structures]) {
                $lodge = Lodge::query()->firstOrCreate(['obedience_id' => $owner->id, 'number' => $number], ['name' => $name, 'rite_id' => $rite->id, 'is_active' => true]);
                foreach ($structures as $structure) {
                    TerritorialAssignment::query()->firstOrCreate(['lodge_id' => $lodge->id, 'structure_id' => $structure->id, 'ended_at' => null], ['obedience_id' => $owner->id, 'level' => $structure->level, 'structure_name' => $structure->name, 'starts_at' => now('UTC')]);
                }
            }
        });
    }
}
