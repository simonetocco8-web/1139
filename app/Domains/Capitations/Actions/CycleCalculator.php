<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Calendar;
use App\Domains\Capitations\Models\PersonRule;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Money;
use App\Domains\Membership\Models\Person;
use Illuminate\Support\Facades\DB;

final class CycleCalculator
{
    /** @return array<int,array<string,mixed>> */
    public function rows(Plan $plan, int $cycle, string $through): array
    {
        $lodgeId = $plan->lodge_id;
        $rows = [];
        $start = Calendar::start($plan, $cycle);
        if ($plan->ends_on !== null && $start->greaterThan($plan->ends_on)) {
            return [];
        }
        $end = Calendar::start($plan, $cycle + 1)->subDay();
        $effectiveEnd = $plan->ends_on !== null && $plan->ends_on->lessThan($end) ? $plan->ends_on : $end;
        $version = $plan->versions->filter(fn ($v) => $v->effective_on->lessThanOrEqualTo($start))->sortByDesc('effective_on')->first();
        if ($version === null || ! $version->is_active) {
            return [];
        }
        foreach (Person::query()->where('lodge_id', $lodgeId)->whereHas('enrollments', fn ($q) => $q->where('starts_on', '<=', min($through, $effectiveEnd->toDateString()))->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString())))->with(['enrollments' => fn ($q) => $q->orderBy('id')])->orderBy('id')->lazy(100) as $person) {
            $days = 0;
            $periods = [];
            $first = null;
            foreach ($person->enrollments as $enrollment) {
                $from = $enrollment->starts_on->greaterThan($start) ? $enrollment->starts_on : $start;
                $to = $enrollment->ends_on !== null && $enrollment->ends_on->lessThan($effectiveEnd) ? $enrollment->ends_on : $effectiveEnd;
                if ($from->greaterThan($to)) {
                    continue;
                }
                $excluded = DB::table('member_exemption_periods')->where('lodge_id', $lodgeId)->where('enrollment_id', $enrollment->id)->orderBy('id')->get();
                $eligible = 0;
                for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                    if (! $excluded->contains(fn ($e) => $day->toDateString() >= $e->starts_on && ($e->ends_on === null || $day->toDateString() <= $e->ends_on))) {
                        $eligible++;
                    }
                }
                // Defensive fallback for imported or legacy flags without intervals.
                if ($excluded->isEmpty() && ($enrollment->administrative_exemption || $enrollment->status === 'suspended')) {
                    $eligible = 0;
                }
                if ($eligible === 0) {
                    continue;
                }
                $days += $eligible;
                $first ??= $enrollment->id;
                $periods[] = ['enrollment_id' => $enrollment->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'version' => $enrollment->version, 'exemptions' => $excluded->map(fn ($e) => ['from' => $e->starts_on, 'to' => $e->ends_on])->all()];
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
            $rows[$person->id] = ['lodge_id' => $lodgeId, 'plan_id' => $plan->id, 'person_id' => $person->id, 'enrollment_id' => $first, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'due_on' => $due->toDateString(), 'original_amount' => Money::decimal($amount), 'snapshot' => ['version_id' => $version->id, 'description' => $version->description, 'base_amount' => Money::decimal($base), 'due_basis' => $version->due_basis, 'due_days' => $version->due_days, 'effective_on' => $version->effective_on->toDateString(), 'prorata' => $version->prorata, 'active_days' => $days, 'cycle_days' => $nominal, 'rule_id' => $rule?->id, 'kind' => $kind, 'discount_bps' => $rule?->discount_bps, 'custom_amount' => $rule?->amount, 'enrollments' => $periods]];
            $rows[$person->id]['snapshot']['source_hash'] = hash('sha256', json_encode($rows[$person->id], JSON_THROW_ON_ERROR));

        }

        return $rows;
    }
}
