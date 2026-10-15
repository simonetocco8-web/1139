<?php

namespace App\Domains\Levies\Actions;

use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Levies\Calendar;
use App\Domains\Levies\Models\Condition;
use App\Domains\Levies\Models\Program;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Rules
{
    public function platform(): void
    {
        VerifiedSession::user();
        Gate::authorize('manage-organization');
    }

    /** @param array<string,mixed> $input */
    public function create(array $input): Program
    {
        $this->platform();
        $v = Validator::make($input, ['obedience_id' => ['required', 'integer'], 'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,40}$/'], 'description' => ['required', 'string', 'max:160'], 'level' => ['required', Rule::in(['national', 'regional', 'provincial'])], 'periodicity' => ['required', Rule::in(array_keys(Calendar::MONTHS))], 'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:2100-01-01'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:2100-12-31']])->validate();

        return DB::transaction(function () use ($v, $input): Program {
            $this->platform();
            Obedience::query()->whereKey($v['obedience_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            abort_if(Program::query()->where('obedience_id', $v['obedience_id'])->where('code', $v['code'])->exists(), 409, 'Codice già utilizzato.');
            $program = Program::query()->create($v + ['created_at' => now('UTC')]);
            $this->insert($program, $input, $v['starts_on'], null, null);
            Audit::record('levy.program.created', VerifiedSession::user()->id, metadata: ['program_id' => $program->id]);

            return $program;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function general(int $programId, array $input): void
    {
        $this->platform();
        $program = Program::query()->findOrFail($programId);
        DB::transaction(function () use ($program, $input): void {
            Obedience::query()->whereKey($program->obedience_id)->lockForUpdate()->firstOrFail();
            $this->platform();
            $v = Validator::make($input, ['structure_id' => ['nullable', 'integer'], 'effective_on' => ['required', 'date_format:Y-m-d', 'after:today']])->validate();
            $structureId = empty($v['structure_id']) ? null : (int) $v['structure_id'];
            if ($structureId !== null) {
                Structure::query()->whereKey($structureId)->where('obedience_id', $program->obedience_id)->where('level', $program->level)->firstOrFail();
            }
            $this->insert($program, $input, $v['effective_on'], $structureId, null);
            Audit::record('levy.condition.created', VerifiedSession::user()->id, metadata: ['program_id' => $program->id]);
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function local(int $lodgeId, int $programId, array $input): void
    {
        $source = app(Access::class)->guard($lodgeId, 'update');
        DB::transaction(function () use ($lodgeId, $programId, $input, $source): void {
            app(Access::class)->lock($lodgeId, $source->obedience_id);
            $lodge = app(Access::class)->guard($lodgeId, 'update');
            $program = Program::query()->where('obedience_id', $lodge->obedience_id)->whereKey($programId)->firstOrFail();
            $v = Validator::make($input, ['effective_on' => ['required', 'date_format:Y-m-d', 'after:today']])->validate();
            $this->insert($program, $input, $v['effective_on'], null, $lodgeId);
            Audit::record('levy.override.created', VerifiedSession::user()->id, $lodgeId, metadata: ['program_id' => $programId]);
        }, 3);
    }

    /** @param array<string,mixed> $input */
    private function insert(Program $program, array $input, string $effective, ?int $structureId, ?int $lodgeId): void
    {
        $v = Validator::make($input, ['ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$effective, 'before_or_equal:2100-12-31'], 'basis' => ['required', Rule::in(['fixed', 'per_member'])], 'amount' => ['required', 'string'], 'count_criterion' => ['required', Rule::in(['active_at_start', 'active_non_exempt_at_start'])], 'due_basis' => ['required', Rule::in(['start', 'end'])], 'due_days' => ['required', 'integer', 'between:0,365'], 'recipient_iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'], 'is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        if (! Calendar::boundary($program, $effective) || ($program->ends_on && $effective > $program->ends_on->toDateString()) || (! empty($v['ends_on']) && ! Calendar::boundary($program, CarbonImmutable::parse($v['ends_on'], 'Europe/Rome')->addDay()->toDateString()))) {
            throw ValidationException::withMessages(['effective_on' => 'La validità deve iniziare e terminare ai confini dei cicli.']);
        }
        $v['amount'] = Money::decimal(Money::cents($v['amount']));
        $scope = $lodgeId !== null ? 'lodge:'.$lodgeId : ($structureId !== null ? 'structure:'.$structureId : 'obedience');
        abort_if(Condition::query()->where('program_id', $program->id)->where('scope_key', $scope)->where('effective_on', $effective)->exists(), 409, 'Versione già presente per questa data.');
        Condition::query()->create($v + ['obedience_id' => $program->obedience_id, 'program_id' => $program->id, 'structure_id' => $structureId, 'lodge_id' => $lodgeId, 'scope_key' => $scope, 'effective_on' => $effective, 'actor_id' => VerifiedSession::user()->id, 'created_at' => now('UTC')]);
    }
}
