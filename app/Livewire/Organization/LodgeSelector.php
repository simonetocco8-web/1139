<?php

namespace App\Livewire\Organization;

use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Actions\ActiveLodge;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class LodgeSelector extends Component
{
    public function boot(): void
    {
        VerifiedSession::user();
    }

    public function mount(ActiveLodge $context): void
    {
        if ($context->current() !== null && ! $context->canChoose()) {
            $this->redirectRoute('dashboard');
        }
    }

    public function select(int $id, ActiveLodge $context): void
    {
        $context->select($id);
        session()->flash('organization_message', 'Loggia attiva aggiornata.');
    }

    public function render(ActiveLodge $context): View
    {
        $user = VerifiedSession::user();

        return view('livewire.organization.lodge-selector', [
            'lodges' => $context->authorized($user)->orderBy('name')->get(),
            'current' => $context->current(),
        ])->layout('components.layouts.organization');
    }
}
