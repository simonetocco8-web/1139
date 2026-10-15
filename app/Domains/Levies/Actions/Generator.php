<?php

namespace App\Domains\Levies\Actions;

use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Levies\Calendar;
use App\Domains\Levies\Models\Condition;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Levies\Models\Program;
use App\Domains\Membership\Models\Enrollment;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\TerritorialAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Generator
{
    public function run(int $lodgeId, string $through, ?int $actorId = null): int
    {
        Validator::make(['through' => $through], ['through' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today']])->validate();

        $source = Lodge::query()->findOrFail($lodgeId);
        if ($actorId !== null) {
            abort_unless(VerifiedSession::user()->id === $actorId, 403);
            app(Access::class)->guard($lodgeId, 'create');
        }

        return DB::transaction(function () use ($lodgeId, $through, $actorId, $source): int {
            $lodge = app(Access::class)->lock($lodgeId, $source->obedience_id);
            if ($actorId !== null) {
                app(Access::class)->guard($lodgeId, 'create');
            }
            abort_unless($lodge->is_active && $lodge->obedience()->where('is_active', true)->exists(), 409);
            $created = 0;
            foreach (Program::query()->where('obedience_id', $lodge->obedience_id)->where('starts_on', '<=', $through)->get() as $program) {
                // Include only this lodge's overrides; never fetch other tenant conditions.
                $conditions = $program->conditions()->where(fn ($q) => $q->whereNull('lodge_id')->orWhere('lodge_id', $lodgeId))->orderByDesc('effective_on')->get();
                for ($cycle = 0; $cycle < 2400; $cycle++) {
                    $start = Calendar::start($program, $cycle);
                    if ($start->toDateString() > $through || ($program->ends_on && $start->greaterThan($program->ends_on))) {
                        break;
                    }
                    if (Obligation::query()->where('lodge_id', $lodgeId)->where('program_id', $program->id)->where('period_start', $start->toDateString())->exists()) {
                        continue;
                    }
                    $instant = $start->startOfDay()->utc();
                    // [starts_at, ended_at): exact historical selection, optional intermediate levels.
                    $assignment = TerritorialAssignment::query()->where('lodge_id', $lodgeId)->where('obedience_id', $lodge->obedience_id)->where('level', $program->level)->where('starts_at', '<=', $instant->format('Y-m-d H:i:s.u'))->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $instant->format('Y-m-d H:i:s.u')))->first();
                    if (! $assignment) {
                        continue;
                    }
                    $chosen = null;
                    foreach (['lodge:'.$lodgeId, 'structure:'.$assignment->structure_id, 'obedience'] as $scope) {
                        $latest = $conditions->first(fn (Condition $c) => $c->scope_key === $scope && $c->effective_on->lessThanOrEqualTo($start));
                        // Expiration falls back to less-specific scope, never resurrects an old version.
                        if ($latest && (! $latest->ends_on || $latest->ends_on->greaterThanOrEqualTo($start))) {
                            $chosen = $latest;
                            break;
                        }
                    }
                    if (! $chosen || ! $chosen->is_active) {
                        continue;
                    }
                    $end = Calendar::start($program, $cycle + 1)->subDay();
                    if ($program->ends_on && $end->greaterThan($program->ends_on)) {
                        $end = $program->ends_on;
                    }
                    $members = [];
                    if ($chosen->basis === 'per_member') {
                        $query = Enrollment::query()->where('lodge_id', $lodgeId)->whereIn('status', ['active', 'ended'])->where('starts_on', '<=', $start->toDateString())->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString()));
                        if ($chosen->count_criterion === 'active_non_exempt_at_start') {
                            $query->where('administrative_exemption', false);
                        }
                        $members = $query->orderBy('person_id')->get()->unique('person_id')->map(fn (Enrollment $e) => ['person_id' => $e->person_id, 'enrollment_id' => $e->id, 'starts_on' => $e->starts_on->toDateString(), 'ends_on' => $e->ends_on?->toDateString(), 'status' => $e->status, 'administrative_exemption' => $e->administrative_exemption])->values()->all();
                    }
                    $units = $chosen->basis === 'fixed' ? 1 : count($members);
                    $unit = Money::cents($chosen->amount);
                    if ($units > 0 && $unit > intdiv(999999999999999, $units)) {
                        throw new \OverflowException('Importo canone oltre DECIMAL(15,2).');
                    }
                    $amount = $unit * $units;
                    $structure = $assignment->structure;
                    $iban = $chosen->recipient_iban ?: $structure->iban;
                    Obligation::query()->create(['lodge_id' => $lodgeId, 'obedience_id' => $lodge->obedience_id, 'program_id' => $program->id, 'condition_id' => $chosen->id, 'assignment_id' => $assignment->id, 'recipient_id' => $assignment->structure_id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'reference_on' => $start->toDateString(), 'due_on' => ($chosen->due_basis === 'start' ? $start : $end)->addDays($chosen->due_days)->toDateString(), 'count_criterion' => $chosen->count_criterion, 'member_count' => count($members), 'amount' => Money::decimal($amount), 'recipient_name' => $assignment->structure_name, 'recipient_iban' => $iban, 'snapshot' => ['code' => $program->code, 'description' => $program->description, 'periodicity' => $program->periodicity, 'level' => $program->level, 'obedience_id' => $lodge->obedience_id, 'condition_id' => $chosen->id, 'scope_key' => $chosen->scope_key, 'effective_on' => $chosen->effective_on->toDateString(), 'condition_ends_on' => $chosen->ends_on?->toDateString(), 'basis' => $chosen->basis, 'unit_amount' => $chosen->amount, 'units' => $units, 'count_criterion' => $chosen->count_criterion, 'reference_on' => $start->toDateString(), 'reference_instant_utc' => $instant->format('Y-m-d H:i:s.u'), 'members' => $members, 'assignment_id' => $assignment->id, 'recipient_id' => $assignment->structure_id, 'recipient_name' => $assignment->structure_name, 'due_basis' => $chosen->due_basis, 'due_days' => $chosen->due_days], 'created_at' => now('UTC')]);
                    $created++;
                }
            }
            if ($created > 0) {
                Audit::record('levy.generated', $actorId, $lodgeId, metadata: ['count' => $created]);
            }

            return $created;
        }, 3);
    }
}
