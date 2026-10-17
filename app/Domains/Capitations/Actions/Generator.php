<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Calendar;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Identity\Audit;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Generator
{
    // Internal service; HTTP callers authorize through Access. Daily generation stays bounded to today.
    public function run(int $lodgeId, string $through, ?int $actorId = null): int
    {
        Validator::make(['through' => $through], ['through' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])->validate();

        return DB::transaction(function () use ($lodgeId, $through, $actorId): int {
            $lodge = Lodge::query()->whereKey($lodgeId)->where('is_active', true)->whereHas('obedience', fn ($q) => $q->where('is_active', true))->lockForUpdate()->firstOrFail();
            $count = 0;
            foreach (Plan::query()->where('lodge_id', $lodgeId)->with('versions')->orderBy('id')->get() as $plan) {
                for ($i = 0; $i < 2400; $i++) {
                    $start = Calendar::start($plan, $i);
                    if ($start->toDateString() > $through || ($plan->ends_on !== null && $start->greaterThan($plan->ends_on))) {
                        break;
                    }
                    foreach (app(CycleCalculator::class)->rows($plan, $i, $through, true) as $row) {
                        if (! Installment::query()->where('lodge_id', $lodgeId)->where('plan_id', $plan->id)->where('person_id', $row['person_id'])->where('period_start', $row['period_start'])->exists()) {
                            Installment::query()->create($row + ['created_at' => now('UTC')]);
                            $count++;
                        }
                    }
                }
            }
            if ($count > 0) {
                Audit::record('capitation.generated', $actorId, $lodge->id, metadata: ['count' => $count]);
            }

            return $count;
        }, 3);
    }
}
