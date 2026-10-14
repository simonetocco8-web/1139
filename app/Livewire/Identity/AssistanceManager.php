<?php

namespace App\Livewire\Identity;

use App\Domains\Identity\Actions\Assistance;
use App\Domains\Identity\Models\AssistedAccess;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AssistanceManager extends Component
{
    public ?int $targetLodge = null;

    public string $reason = '';

    public int $minutes = 30;

    /** @var list<string> */
    public array $permissions = ['read'];

    public function boot(): void
    {
        abort_unless(VerifiedSession::user()->is_platform_admin, 403);
    }

    public function request(Assistance $assistance): void
    {
        abort_if($this->targetLodge === null, 422);
        $assistance->request($this->targetLodge, $this->reason, $this->permissions, $this->minutes);
        $this->reason = '';
        session()->flash('status', 'Richiesta registrata. Occorre l’approvazione di un amministratore della loggia.');
    }

    public function revoke(int $id, Assistance $assistance): void
    {
        $assistance->revoke($id);
    }

    public function render(): View
    {
        $user = VerifiedSession::user();
        abort_unless($user->is_platform_admin, 403);

        return view('livewire.identity.assistance-manager', ['lodges' => Lodge::query()->where('is_active', true)->orderBy('name')->get(), 'requests' => AssistedAccess::query()->where('requester_id', $user->id)->orderByDesc('id')->limit(20)->get()])->layout('components.layouts.organization');
    }
}
