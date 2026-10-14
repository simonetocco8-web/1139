<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Calendar;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\PersonRule;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Models\PlanVersion;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Plans
{
    public function __construct(private Access $access) {}

    /** @param array<string,mixed> $input */
    public function create(int $lodgeId, array $input): Plan
    {
        $this->access->guard($lodgeId, 'create');

        return DB::transaction(function () use ($lodgeId, $input): Plan {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $this->access->guard($lodgeId, 'create');
            $v = Validator::make($input, ['description' => ['required', 'string', 'max:160'], 'periodicity' => ['required', Rule::in(array_keys(Calendar::MONTHS))], 'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-01-01'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:2100-12-31']])->validate();
            $economic = $this->economic($input);
            $plan = Plan::query()->create($v + ['lodge_id' => $lodgeId, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            PlanVersion::query()->create($economic + ['lodge_id' => $lodgeId, 'plan_id' => $plan->id, 'effective_on' => $v['starts_on'], 'created_at' => now('UTC')]);
            Audit::record('capitation.plan.created', VerifiedSession::user()->id, $lodgeId, metadata: ['plan_id' => $plan->id]);

            return $plan;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function revise(int $lodgeId, int $id, array $input): void
    {
        $this->access->guard($lodgeId, 'update');
        DB::transaction(function () use ($lodgeId, $id, $input): void {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $this->access->guard($lodgeId, 'update');
            $plan = Plan::query()->where('lodge_id', $lodgeId)->whereKey($id)->firstOrFail();
            $v = Validator::make($input, ['effective_on' => ['required', 'date_format:Y-m-d', 'after:today', 'before_or_equal:2100-01-01']])->validate();
            $this->boundary($plan, $v['effective_on']);
            abort_if($plan->versions()->where('effective_on', $v['effective_on'])->exists(), 409, 'Una versione esiste già per questo ciclo.');
            PlanVersion::query()->create($this->economic($input) + ['lodge_id' => $lodgeId, 'plan_id' => $id, 'effective_on' => $v['effective_on'], 'created_at' => now('UTC')]);
            Audit::record('capitation.plan.revised', VerifiedSession::user()->id, $lodgeId, metadata: ['plan_id' => $id]);
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function rule(int $lodgeId, int $planId, int $personId, array $input): void
    {
        $this->access->guard($lodgeId, 'update');
        DB::transaction(function () use ($lodgeId, $planId, $personId, $input): void {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $this->access->guard($lodgeId, 'update');
            $this->access->person($lodgeId, $personId);
            $plan = Plan::query()->where('lodge_id', $lodgeId)->whereKey($planId)->firstOrFail();
            $v = Validator::make($input, ['effective_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_on'], 'kind' => ['required', Rule::in(['standard', 'exempt', 'discount', 'custom'])], 'reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
            $this->boundary($plan, $v['effective_on']);
            if ($v['effective_on'] <= now()->toDateString() && (Installment::query()->where('lodge_id', $lodgeId)->where('plan_id', $planId)->where('person_id', $personId)->exists() || PersonRule::query()->where('lodge_id', $lodgeId)->where('plan_id', $planId)->where('person_id', $personId)->exists())) {
                throw ValidationException::withMessages(['effective_on' => 'Condizioni già avviate: usa un ciclo futuro o una rettifica.']);
            }
            if (! empty($v['ends_on'])) {
                $this->boundary($plan, CarbonImmutable::parse($v['ends_on'])->addDay()->toDateString(), false);
            }
            abort_if(PersonRule::query()->where('lodge_id', $lodgeId)->where('plan_id', $planId)->where('person_id', $personId)->where('effective_on', $v['effective_on'])->exists(), 409);
            $amount = null;
            $bps = null;
            if ($v['kind'] === 'custom') {
                $amount = Money::decimal(Money::cents((string) ($input['amount'] ?? '')));
            }
            if ($v['kind'] === 'discount') {
                $bps = Money::cents((string) ($input['discount_percent'] ?? ''));
                if ($bps > 10000) {
                    throw ValidationException::withMessages(['discount_percent' => 'Sconto massimo 100%.']);
                }
            }
            PersonRule::query()->create($v + ['lodge_id' => $lodgeId, 'plan_id' => $planId, 'person_id' => $personId, 'amount' => $amount, 'discount_bps' => $bps, 'created_at' => now('UTC')]);
            Audit::record('capitation.rule.created', VerifiedSession::user()->id, $lodgeId, metadata: ['plan_id' => $planId, 'person_id' => $personId]);
        }, 3);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function economic(array $input): array
    {
        $v = Validator::make($input, ['description' => ['required', 'string', 'max:160'], 'amount' => ['required', 'string'], 'due_basis' => ['required', Rule::in(['start', 'end'])], 'due_days' => ['required', 'integer', 'between:0,60'], 'prorata' => ['required', Rule::in(['none', 'daily'])], 'is_active' => ['required', 'boolean']])->validate();
        $amount = Money::cents($v['amount']);
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => 'Il piano deve avere un importo positivo.']);
        }$v['amount'] = Money::decimal($amount);

        return $v;
    }

    private function boundary(Plan $plan, string $date, bool $checkEnd = true): void
    {
        if (! Calendar::boundary($plan, $date) || ($checkEnd && $plan->ends_on !== null && $date > $plan->ends_on->toDateString())) {
            throw ValidationException::withMessages(['effective_on' => 'La data deve essere un inizio ciclo valido del piano.']);
        }
    }
}
