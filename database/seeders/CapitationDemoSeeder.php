<?php

namespace Database\Seeders;

use App\Domains\Capitations\Actions\Generator;
use App\Domains\Capitations\Models\PersonRule;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Models\PlanVersion;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CapitationDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Dati fittizi solo in locale/testing.');
        }
        $this->call(MembershipDemoSeeder::class);
        foreach (Lodge::query()->whereHas('obedience', fn ($q) => $q->whereIn('code', ['DEMO_A', 'DEMO_B']))->whereIn('number', ['17', '18'])->get() as $lodge) {
            DB::transaction(function () use ($lodge): void {
                $p = Plan::query()->where('lodge_id', $lodge->id)->where('description', 'Capitazione Fittizia Mensile')->first();
                if ($p === null) {
                    $start = now()->startOfYear()->toDateString();
                    $p = Plan::query()->create(['lodge_id' => $lodge->id, 'description' => 'Capitazione Fittizia Mensile', 'periodicity' => 'monthly', 'starts_on' => $start, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                    PlanVersion::query()->create(['lodge_id' => $lodge->id, 'plan_id' => $p->id, 'effective_on' => $start, 'description' => $p->description, 'amount' => '31.00', 'due_basis' => 'end', 'due_days' => 5, 'prorata' => 'daily', 'is_active' => true, 'created_at' => now('UTC')]);
                    $person = $lodge->people()->where('reference', 'DEMO_2')->first();
                    if ($person !== null) {
                        PersonRule::query()->create(['lodge_id' => $lodge->id, 'plan_id' => $p->id, 'person_id' => $person->id, 'effective_on' => $start, 'kind' => 'discount', 'discount_bps' => 2000, 'reason' => 'Sconto dimostrativo completamente fittizio', 'created_at' => now('UTC')]);
                    }
                }
            }, 3);
            app(Generator::class)->run($lodge->id, now()->toDateString());
        }
    }
}
