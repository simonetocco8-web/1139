<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Calendar;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\PersonRule;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Generator
{
    // Internal server service: HTTP dispatchers must authorize through Access.
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
                    $end = Calendar::start($plan, $i + 1)->subDay();
                    $effectiveEnd = $plan->ends_on !== null && $plan->ends_on->lessThan($end) ? $plan->ends_on : $end;
                    $version = $plan->versions->filter(fn ($v) => $v->effective_on->lessThanOrEqualTo($start))->sortByDesc('effective_on')->first();
                    if ($version === null || ! $version->is_active) {
                        continue;
                    }
                    foreach (Person::query()->where('lodge_id', $lodgeId)->whereHas('enrollments', fn ($q) => $q->where('starts_on', '<=', min($through, $effectiveEnd->toDateString()))->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString())))->with('enrollments')->lazy(100) as $person) {
                        if (Installment::query()->where('lodge_id', $lodgeId)->where('plan_id', $plan->id)->where('person_id', $person->id)->where('period_start', $start->toDateString())->exists()) {
                            continue;
                        }
                        $days = 0;
                        $periods = [];
                        $first = null;
                        foreach ($person->enrollments as $enrollment) {
                            $from = $enrollment->starts_on->greaterThan($start) ? $enrollment->starts_on : $start;
                            $to = $enrollment->ends_on !== null && $enrollment->ends_on->lessThan($effectiveEnd) ? $enrollment->ends_on : $effectiveEnd;
                            if ($from->greaterThan($to)) {
                                continue;
                            }
                            $days += (int) $from->diffInDays($to) + 1;
                            $first ??= $enrollment->id;
                            $periods[] = ['enrollment_id' => $enrollment->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()];
                        }
                        if ($days === 0 || $first === null) {
                            continue;
                        }
                        $rule = PersonRule::query()->where('lodge_id', $lodgeId)->where('plan_id', $plan->id)->where('person_id', $person->id)->where('effective_on', '<=', $start->toDateString())->orderByDesc('effective_on')->first();
                        // Latest version wins even after its end: expired rules return to standard.
                        if ($rule !== null && $rule->ends_on !== null && $rule->ends_on->lessThan($start)) {
                            $rule = null;
                        }
                        $amount = Money::cents($version->amount);
                        $base = $amount;
                        $kind = $rule === null ? 'standard' : $rule->kind;
                        if ($rule !== null && $kind === 'exempt') {
                            $amount = 0;
                        } elseif ($rule !== null && $kind === 'custom') {
                            if ($rule->amount === null) {
                                throw new \LogicException('Importo personalizzato mancante.');
                            }
                            $amount = Money::cents($rule->amount);
                        } elseif ($rule !== null && $kind === 'discount') {
                            if ($rule->discount_bps === null) {
                                throw new \LogicException('Sconto mancante.');
                            }
                            $amount = Money::ratio($amount, 10000 - $rule->discount_bps, 10000);
                        }
                        $nominal = (int) $start->diffInDays($end) + 1;
                        if ($version->prorata === 'daily') {
                            $amount = Money::ratio($amount, $days, $nominal);
                        }
                        $due = ($version->due_basis === 'start' ? $start : $end)->addDays($version->due_days);
                        Installment::query()->create(['lodge_id' => $lodgeId, 'plan_id' => $plan->id, 'person_id' => $person->id, 'enrollment_id' => $first, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'due_on' => $due->toDateString(), 'original_amount' => Money::decimal($amount), 'snapshot' => ['version_id' => $version->id, 'description' => $version->description, 'base_amount' => Money::decimal($base), 'due_basis' => $version->due_basis, 'due_days' => $version->due_days, 'effective_on' => $version->effective_on->toDateString(), 'prorata' => $version->prorata, 'active_days' => $days, 'cycle_days' => $nominal, 'rule_id' => $rule?->id, 'kind' => $kind, 'discount_bps' => $rule?->discount_bps, 'custom_amount' => $rule?->amount, 'enrollments' => $periods], 'created_at' => now('UTC')]);
                        $count++;
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
