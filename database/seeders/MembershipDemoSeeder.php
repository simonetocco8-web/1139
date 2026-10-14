<?php

namespace Database\Seeders;

use App\Domains\Membership\Models\Enrollment;
use App\Domains\Membership\Models\MemberHistory;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MembershipDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Dati fittizi consentiti solo in locale/testing.');
        }
        $this->call(OrganizationDemoSeeder::class);
        DB::transaction(function (): void {
            foreach (Lodge::query()->whereHas('obedience', fn ($q) => $q->whereIn('code', ['DEMO_A', 'DEMO_B']))->whereIn('number', ['17', '18'])->orderBy('id')->get() as $lodge) {
                foreach (['active', 'suspended', 'ended'] as $n => $status) {
                    $person = Person::unguarded(fn () => Person::query()->firstOrCreate(['lodge_id' => $lodge->id, 'reference' => 'DEMO_'.($n + 1)], ['first_name' => 'NomeFittizio'.($n + 1), 'last_name' => 'CognomeFittizio'.($n + 1), 'email' => 'persona-'.$lodge->id.'-'.($n + 1).'@example.invalid', 'version' => 1]));
                    if (! $person->enrollments()->whereDate('starts_on', '2020-01-01')->exists()) {
                        Enrollment::unguarded(fn () => Enrollment::query()->firstOrCreate(['lodge_id' => $lodge->id, 'person_id' => $person->id, 'starts_on' => '2020-01-01'], ['ends_on' => $status === 'ended' ? '2021-12-31' : null, 'status' => $status, 'administrative_exemption' => $n === 1, 'exemption_reason' => $n === 1 ? 'Esenzione dimostrativa completamente fittizia' : null]));
                    }
                    if (! $person->history()->exists()) {
                        MemberHistory::query()->create(['lodge_id' => $lodge->id, 'person_id' => $person->id, 'actor_id' => null, 'event' => 'created', 'snapshot' => ['person' => $person->attributesToArray(), 'enrollments' => $person->enrollments()->get()->map(fn ($e) => $e->attributesToArray())->all()], 'occurred_at' => now('UTC')]);
                    }
                }
            }
        });
    }
}
