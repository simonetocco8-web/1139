<?php

namespace App\Livewire\Organization;

use App\Domains\Organization\Actions\OrganizationActions;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use App\Domains\Organization\Models\Structure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    #[Locked]
    public string $kind = 'obediences';

    #[Locked]
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $search = '';

    public string $status = 'all';

    /** @var array<string, mixed> */
    public array $form = [];

    public function boot(): void
    {
        OrganizationActions::authorize();
    }

    public function mount(string $kind): void
    {
        abort_unless(isset(OrganizationActions::MODELS[$kind]), 404);
        $this->kind = $kind;
        $this->resetForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFormObedienceId(): void
    {
        if ($this->editingId === null) {
            $this->form['parent_id'] = null;
            $this->form['rite_id'] = null;
            $this->form['structure_ids'] = [];
        }
    }

    public function create(): void
    {
        OrganizationActions::authorize();
        $this->resetForm();
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        OrganizationActions::authorize();
        $class = OrganizationActions::MODELS[$this->kind];
        $record = $class::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $id;
        foreach (array_keys($this->form) as $field) {
            if (array_key_exists($field, $record->getAttributes())) {
                $this->form[$field] = $record->getAttribute($field);
            }
        }
        if ($record instanceof Obedience) {
            $this->form['rite_ids'] = $record->rites()->pluck('rites.id')->all();
        }
        if ($record instanceof Lodge) {
            $this->form['structure_ids'] = $record->currentAssignments()->pluck('structure_id')->all();
        }
        $this->showForm = true;
    }

    public function save(OrganizationActions $actions): void
    {
        OrganizationActions::authorize();
        foreach (['code', 'name', 'number', 'email', 'phone', 'address', 'iban'] as $field) {
            if (is_string($this->form[$field] ?? null)) {
                $this->form[$field] = trim($this->form[$field]);
            }
        }
        $this->form['code'] = strtoupper((string) ($this->form['code'] ?? ''));
        $this->form['iban'] = str_replace(' ', '', strtoupper((string) ($this->form['iban'] ?? '')));
        try {
            $actions->save($this->kind, $this->form, $this->editingId);
        } catch (QueryException) {
            $this->addError('form', 'Salvataggio non riuscito: verificare duplicati e collegamenti, quindi riprovare.');

            return;
        }
        $this->resetForm();
        session()->flash('organization_message', 'Dati salvati.');
        $this->resetPage();
    }

    public function setActive(int $id, bool $active, OrganizationActions $actions): void
    {
        $actions->setActive($this->kind, $id, $active);
        session()->flash('organization_message', $active ? 'Voce riattivata.' : 'Voce disattivata. Lo storico è conservato.');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->showForm = false;
        $this->form = ['code' => '', 'name' => '', 'number' => '', 'email' => '', 'phone' => '', 'address' => '', 'iban' => '', 'is_active' => true, 'obedience_id' => null, 'rite_id' => null, 'parent_id' => null, 'level' => 'national', 'rite_ids' => [], 'structure_ids' => []];
    }

    public function render(): View
    {
        OrganizationActions::authorize();
        $class = OrganizationActions::MODELS[$this->kind];
        $query = $class::query()->orderBy('name');
        if ($this->search !== '') {
            $search = mb_substr($this->search, 0, 160);
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%');
                if (in_array($this->kind, ['obediences', 'rites'], true)) {
                    $q->orWhere('code', 'like', '%'.$search.'%');
                } elseif ($this->kind === 'lodges') {
                    $q->orWhere('number', 'like', '%'.$search.'%');
                }
            });
        }
        if (in_array($this->status, ['active', 'inactive'], true)) {
            $query->where('is_active', $this->status === 'active');
        }
        if (in_array($this->kind, ['structures', 'lodges'], true)) {
            $query->with('obedience');
        }
        if ($this->kind === 'structures') {
            $query->with('parent');
        }
        if ($this->kind === 'lodges') {
            $query->with('rite', 'currentAssignments');
        }
        $ownerId = $this->form['obedience_id'] ?? null;

        return view('livewire.organization.manager', [
            'records' => $query->paginate(10),
            'obediences' => Obedience::query()->orderBy('name')->get(),
            'rites' => $this->kind === 'lodges'
                ? Rite::query()->whereHas('obediences', fn ($q) => $q->where('obediences.id', $ownerId))->orderBy('name')->get()
                : Rite::query()->orderBy('name')->get(),
            'structures' => Structure::query()->where('obedience_id', $ownerId)->orderBy('level')->orderBy('name')->get(),
            'history' => $this->kind === 'lodges' && $this->editingId !== null
                ? Lodge::query()->findOrFail($this->editingId)->assignments()->orderByDesc('starts_at')->orderByDesc('id')->get()
                : collect(),
        ])->layout('components.layouts.organization');
    }
}
