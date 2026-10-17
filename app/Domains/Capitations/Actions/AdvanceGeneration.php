<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Calendar;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdvanceGeneration
{
    /** @return array<string,string> */
    public function cycles(Plan $plan): array
    {
        $out = [];
        for ($i = 0; $i < 2400; $i++) {
            $start = Calendar::start($plan, $i);
            if ($start->greaterThan(now()->addYears(5)) || ($plan->ends_on !== null && $start->greaterThan($plan->ends_on))) {
                break;
            }
            if ($start->greaterThan(now()->startOfDay())) {
                $out[$start->toDateString()] = $start->format('d/m/Y').' — '.Calendar::start($plan, $i + 1)->subDay()->format('d/m/Y');
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function projection(int $lodgeId, int $planId, string $cycle, bool $authorize = true): array
    {
        if ($authorize) {
            app(Access::class)->guard($lodgeId, 'create');
        }
        $plan = Plan::query()->where('lodge_id', $lodgeId)->whereKey($planId)->with('versions')->firstOrFail();
        if (! isset($this->cycles($plan)[$cycle])) {
            throw ValidationException::withMessages(['cycle' => 'Seleziona un ciclo futuro valido entro cinque anni.']);
        }
        $index = $this->index($plan, $cycle);
        $end = Calendar::start($plan, $index + 1)->subDay()->toDateString();
        $expected = app(CycleCalculator::class)->rows($plan, $index, $end);
        $existing = Installment::query()->where('lodge_id', $lodgeId)->where('plan_id', $planId)->where('period_start', $cycle)->orderBy('person_id')->get()->keyBy('person_id');
        $ids = array_unique(array_merge(array_keys($expected), $existing->keys()->all()));
        sort($ids);
        $people = Person::query()->where('lodge_id', $lodgeId)->whereIn('id', $ids)->get()->keyBy('id');
        $rows = [];
        foreach ($ids as $id) {
            $old = $existing->get($id);
            $candidate = $expected[$id] ?? null;
            $rows[] = ['person_id' => $id, 'label' => $people[$id]->last_name.' '.$people[$id]->first_name.' · '.$people[$id]->reference, 'candidate' => $candidate, 'existing_id' => $old?->id, 'existing_amount' => $old === null ? null : Money::decimal(Balances::installment($old)['due']), 'changed' => $old !== null && ($candidate === null || ($old->snapshot['source_hash'] ?? null) !== $candidate['snapshot']['source_hash'])];
        }

        return ['plan_id' => $planId, 'description' => $plan->description, 'cycle' => $cycle, 'end' => $end, 'rows' => $rows];
    }

    /** @return array<string,mixed> */
    public function preview(int $lodgeId, int $planId, string $cycle): array
    {
        return DB::transaction(function () use ($lodgeId, $planId, $cycle): array {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $data = $this->projection($lodgeId, $planId, $cycle);
            $token = bin2hex(random_bytes(24));
            session()->put('capitation.advance', ['token' => $token, 'lodge' => $lodgeId, 'actor' => VerifiedSession::user()->id, 'plan' => $planId, 'cycle' => $cycle, 'hash' => $this->hash($data), 'expires' => now()->addMinutes(15)->getTimestamp()]);

            return $data + ['token' => $token];
        }, 3);
    }

    public function confirm(int $lodgeId, string $token): int
    {
        app(Access::class)->guard($lodgeId, 'create');
        $saved = session('capitation.advance');
        if (! is_array($saved) || ! hash_equals($saved['token'], $token) || $saved['lodge'] !== $lodgeId || $saved['actor'] !== VerifiedSession::user()->id || $saved['expires'] <= now()->getTimestamp()) {
            throw ValidationException::withMessages(['preview' => 'Anteprima scaduta o non valida: genera una nuova anteprima.']);
        }
        if (isset($saved['completed'])) {
            return 0;
        }
        $count = DB::transaction(function () use ($lodgeId, $saved): int {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $data = $this->projection($lodgeId, $saved['plan'], $saved['cycle']);
            if (! hash_equals($saved['hash'], $this->hash($data))) {
                throw ValidationException::withMessages(['preview' => 'I dati sono cambiati dopo l’anteprima. Riapri l’anteprima e verifica i nuovi importi prima di confermare.']);
            }
            $count = 0;
            foreach ($data['rows'] as $row) {
                if ($row['existing_id'] === null && $row['candidate'] !== null) {
                    $candidate = $row['candidate'];
                    $candidate['snapshot']['generated_early'] = true;
                    $candidate['snapshot']['original_due_on'] = $candidate['due_on'];
                    $candidate['snapshot']['generated_by'] = VerifiedSession::user()->id;
                    Installment::query()->create($candidate + ['created_at' => now('UTC')]);
                    $count++;
                }
            }
            Audit::record('capitation.advance.generated', VerifiedSession::user()->id, $lodgeId, metadata: ['plan_id' => $saved['plan'], 'cycle' => $saved['cycle'], 'count' => $count, 'preview_hash' => $saved['hash']]);

            return $count;
        }, 3);
        session()->put('capitation.advance.completed', true);

        return $count;
    }

    public function changed(Installment $installment): bool
    {
        if (! ($installment->snapshot['generated_early'] ?? false) || $installment->period_start->toDateString() <= now()->toDateString()) {
            return false;
        }
        $plan = Plan::query()->where('lodge_id', $installment->lodge_id)->whereKey($installment->plan_id)->with('versions')->firstOrFail();
        $rows = app(CycleCalculator::class)->rows($plan, $this->index($plan, $installment->period_start->toDateString()), $installment->period_end->toDateString());

        $candidate = $rows[$installment->person_id] ?? null;
        $hash = $candidate['snapshot']['source_hash'] ?? hash('sha256', 'excluded:'.$installment->id);
        $last = $installment->corrections()->where('idempotency_key', 'like', 'forecast:%')->orderByDesc('id')->first();

        return ($last->fingerprint ?? $installment->snapshot['source_hash']) !== $hash || ($candidate !== null && $candidate['due_on'] !== $installment->due_on->toDateString());
    }

    private function index(Plan $plan, string $cycle): int
    {
        for ($i = 0; $i < 2400; $i++) {
            if (Calendar::start($plan, $i)->toDateString() === $cycle) {
                return $i;
            }
        }
        throw new \LogicException('Ciclo non valido.');
    }

    /** @param array<string,mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
