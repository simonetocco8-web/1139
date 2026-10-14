<?php

namespace App\Domains\Organization\Actions;

use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\OrganizationalModel;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use App\Domains\Organization\Models\TerritorialAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OrganizationActions
{
    public const MODELS = ['obediences' => Obedience::class, 'rites' => Rite::class, 'structures' => Structure::class, 'lodges' => Lodge::class];

    public const LEVELS = ['national' => 0, 'regional' => 1, 'provincial' => 2];

    public static function authorize(): void
    {
        VerifiedSession::user();
        Gate::authorize('manage-organization');
    }

    /** @param array<string, mixed> $data */
    public function save(string $kind, array $data, ?int $id = null): OrganizationalModel
    {
        self::authorize();
        abort_unless(isset(self::MODELS[$kind]), 404);

        return DB::transaction(function () use ($kind, $data, $id): OrganizationalModel {
            $class = self::MODELS[$kind];
            // One serialization lock per obedience for hierarchy/territorial changes.
            // Rites use all obediences in stable order when compatibility is changed.
            if ($kind === 'rites') {
                Obedience::query()->orderBy('id')->lockForUpdate()->get();
            } elseif ($kind === 'obediences' && $id !== null) {
                Obedience::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            } elseif (in_array($kind, ['structures', 'lodges'], true)) {
                Obedience::query()->whereKey($data['obedience_id'] ?? null)->lockForUpdate()->firstOrFail();
            }
            $model = $id === null ? new $class : $class::query()->lockForUpdate()->findOrFail($id);
            Gate::authorize($id === null ? 'create' : 'update', $id === null ? $class : $model);
            $values = $this->validated($kind, $data, $id);
            if (in_array($kind, ['structures', 'lodges'], true)) {
                $owner = Obedience::query()->whereKey($values['obedience_id'])->firstOrFail();
                if ($id !== null && $model->getAttribute('obedience_id') !== $owner->id) {
                    $this->invalid('obedience_id', 'L’obbedienza non può cambiare: creare una nuova entità.');
                }
                if (! $owner->is_active && $id === null) {
                    $this->invalid('obedience_id', 'L’obbedienza selezionata è inattiva.');
                }
            }
            if ($model instanceof Structure) {
                $this->validateParent($model, $values);
            }
            if ($model instanceof Lodge) {
                $rite = Rite::query()->whereKey($values['rite_id'])->firstOrFail();
                if (! $rite->is_active && ($id === null || $model->rite_id !== $rite->id)) {
                    $this->invalid('rite_id', 'Il rito selezionato è inattivo.');
                }
                if (! DB::table('obedience_rite')->where('obedience_id', $values['obedience_id'])->where('rite_id', $rite->id)->exists()) {
                    $this->invalid('rite_id', 'Il rito non è compatibile con questa obbedienza.');
                }
            }
            $model->fill($values);
            $hierarchyChanged = $model instanceof Structure && $model->exists && $model->isDirty('parent_id');
            $model->save();
            if ($hierarchyChanged) {
                $this->validateCurrentLodgeChains($model);
            }
            if ($model instanceof Obedience) {
                $this->syncRites($model, $data['rite_ids'] ?? []);
            }
            if ($model instanceof Lodge) {
                $this->assignTerritory($model, $data['structure_ids'] ?? []);
            }
            $this->audit($kind, $model->id, $id === null ? 'created' : 'updated');

            return $model;
        }, 3);
    }

    public function setActive(string $kind, int $id, bool $active): void
    {
        self::authorize();
        abort_unless(isset(self::MODELS[$kind]), 404);
        DB::transaction(function () use ($kind, $id, $active): void {
            $class = self::MODELS[$kind];
            if ($kind === 'rites') {
                Obedience::query()->orderBy('id')->lockForUpdate()->get();
            } else {
                $ownerId = $kind === 'obediences' ? $id : $class::query()->findOrFail($id)->getAttribute('obedience_id');
                Obedience::query()->whereKey($ownerId)->lockForUpdate()->firstOrFail();
            }
            $model = $class::query()->lockForUpdate()->findOrFail($id);
            Gate::authorize('update', $model);
            $model->is_active = $active;
            $model->save();
            $this->audit($kind, $id, $active ? 'activated' : 'deactivated');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(string $kind, array $data, ?int $id): array
    {
        $rules = ['name' => ['required', 'string', 'max:160'], 'is_active' => ['required', 'boolean']];
        if (in_array($kind, ['obediences', 'rites'], true)) {
            $rules['code'] = ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique($kind, 'code')->ignore($id)];
        }
        if ($kind !== 'rites') {
            $rules += ['email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:255']];
        }
        if (in_array($kind, ['structures', 'lodges'], true)) {
            $rules += ['obedience_id' => ['required', 'integer', Rule::exists('obediences', 'id')], 'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/']];
        }
        if ($kind === 'structures') {
            $rules += ['level' => ['required', Rule::in(array_keys(self::LEVELS))], 'parent_id' => ['nullable', 'integer', Rule::exists('structures', 'id')]];
        }
        if ($kind === 'lodges') {
            $rules += ['rite_id' => ['required', 'integer', Rule::exists('rites', 'id')], 'number' => ['required', 'string', 'max:40', Rule::unique('lodges', 'number')->where('obedience_id', $data['obedience_id'] ?? null)->ignore($id)]];
        }
        $values = Validator::make($data, $rules, [], ['name' => 'denominazione', 'code' => 'identificativo', 'number' => 'numero', 'obedience_id' => 'obbedienza', 'rite_id' => 'rito', 'parent_id' => 'struttura genitore', 'iban' => 'IBAN'])->validate();

        foreach (['parent_id', 'email', 'phone', 'address', 'iban'] as $field) {
            if (array_key_exists($field, $values) && $values[$field] === '') {
                $values[$field] = null;
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function validateParent(Structure $structure, array $values): void
    {
        if ($structure->exists && $structure->level !== $values['level']) {
            $this->invalid('level', 'Il livello non può cambiare: lo storico deve rimanere coerente.');
        }
        if (empty($values['parent_id'])) {
            return;
        }
        $parent = Structure::query()->whereKey($values['parent_id'])->firstOrFail();
        if ($parent->id === $structure->id) {
            $this->invalid('parent_id', 'Una struttura non può essere genitore di sé stessa.');
        }
        if ($parent->obedience_id !== (int) $values['obedience_id']) {
            $this->invalid('parent_id', 'Il genitore deve appartenere alla stessa obbedienza.');
        }
        if (! $parent->is_active && (! $structure->exists || $structure->parent_id !== $parent->id)) {
            $this->invalid('parent_id', 'Il nuovo genitore deve essere attivo.');
        }
        if (self::LEVELS[$parent->level] >= self::LEVELS[$values['level']]) {
            $this->invalid('parent_id', 'Il genitore deve avere un livello superiore.');
        }
        $visited = [];
        while ($parent !== null) {
            if ($parent->id === $structure->id || isset($visited[$parent->id])) {
                $this->invalid('parent_id', 'La gerarchia non può contenere cicli.');
            }
            $visited[$parent->id] = true;
            $parent = $parent->parent;
        }
    }

    private function syncRites(Obedience $obedience, mixed $ids): void
    {
        $validated = Validator::make(['rite_ids' => $ids], ['rite_ids' => ['array'], 'rite_ids.*' => ['integer', 'distinct', Rule::exists('rites', 'id')]])->validate();
        $ids = array_map('intval', $validated['rite_ids']);
        if ($obedience->lodges()->whereNotIn('rite_id', $ids)->exists()) {
            $this->invalid('rite_ids', 'Un rito utilizzato da una loggia non può essere dissociato.');
        }
        $existing = $obedience->rites()->pluck('rites.id')->all();
        if (Rite::query()->whereIn('id', array_diff($ids, $existing))->where('is_active', false)->exists()) {
            $this->invalid('rite_ids', 'Non è possibile associare un nuovo rito inattivo.');
        }
        $obedience->rites()->sync($ids);
    }

    private function assignTerritory(Lodge $lodge, mixed $ids): void
    {
        $validated = Validator::make(['structure_ids' => $ids], ['structure_ids' => ['array', 'max:3'], 'structure_ids.*' => ['integer', 'distinct', Rule::exists('structures', 'id')]])->validate();
        $selected = Structure::query()->whereIn('id', $validated['structure_ids'])->get();
        $current = $lodge->currentAssignments()->get();
        $levels = [];
        foreach ($selected as $structure) {
            if ($structure->obedience_id !== $lodge->obedience_id) {
                $this->invalid('structure_ids', 'Tutte le strutture devono appartenere all’obbedienza della loggia.');
            }
            if (! $structure->is_active && ! $current->contains('structure_id', $structure->id)) {
                $this->invalid('structure_ids', 'Non è possibile collegare una nuova struttura inattiva.');
            }
            if (isset($levels[$structure->level])) {
                $this->invalid('structure_ids', 'Selezionare al massimo una struttura per livello.');
            }
            $levels[$structure->level] = $structure->id;
            foreach ($selected as $ancestor) {
                if (self::LEVELS[$ancestor->level] < self::LEVELS[$structure->level] && ! $this->isAncestor($ancestor, $structure)) {
                    $this->invalid('structure_ids', 'I collegamenti territoriali devono appartenere alla stessa catena gerarchica.');
                }
            }
        }
        $now = now('UTC');
        foreach ($current as $assignment) {
            if (! $selected->contains('id', $assignment->structure_id)) {
                $assignment->ended_at = $now;
                $assignment->save();
            }
        }
        foreach ($selected as $structure) {
            if (! $current->contains('structure_id', $structure->id)) {
                TerritorialAssignment::query()->create(['lodge_id' => $lodge->id, 'obedience_id' => $lodge->obedience_id, 'structure_id' => $structure->id, 'level' => $structure->level, 'structure_name' => $structure->name, 'starts_at' => $now]);
            }
        }
    }

    private function validateCurrentLodgeChains(Structure $changed): void
    {
        $lodges = Lodge::query()->where('obedience_id', $changed->obedience_id)
            ->with('currentAssignments.structure')->get();
        foreach ($lodges as $lodge) {
            foreach ($lodge->currentAssignments as $lower) {
                foreach ($lodge->currentAssignments as $upper) {
                    if (self::LEVELS[$upper->level] < self::LEVELS[$lower->level]
                        && ! $this->isAncestor($upper->structure, $lower->structure)) {
                        $this->invalid('parent_id', 'Il nuovo genitore renderebbe incoerenti i collegamenti attuali di una loggia. Aggiornare prima i collegamenti della loggia.');
                    }
                }
            }
        }
    }

    private function isAncestor(Structure $ancestor, Structure $descendant): bool
    {
        $visited = [];
        $parent = $descendant->parent;
        while ($parent !== null && ! isset($visited[$parent->id])) {
            if ($parent->id === $ancestor->id) {
                return true;
            }
            $visited[$parent->id] = true;
            $parent = $parent->parent;
        }

        return false;
    }

    private function audit(string $kind, int $id, string $event): void
    {
        DB::table('organization_events')->insert(['actor_id' => VerifiedSession::user()->id, 'entity_type' => $kind, 'entity_id' => $id, 'event' => $event, 'occurred_at' => now('UTC')]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
