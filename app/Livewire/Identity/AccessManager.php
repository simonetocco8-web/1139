<?php

namespace App\Livewire\Identity;

use App\Domains\Identity\Actions\Assistance;
use App\Domains\Identity\Actions\Invitations;
use App\Domains\Identity\Actions\LocalRoles;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\Delivery\DeliveryUnavailable;
use App\Domains\Identity\Models\AssistedAccess;
use App\Domains\Identity\Models\Invitation;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class AccessManager extends Component
{
    use WithPagination;

    #[Locked]
    public int $lodgeId = 0;

    #[Locked]
    public ?int $editingUser = null;

    public string $email = '';

    public string $search = '';

    /** @var list<string> */
    public array $inviteRoles = ['brother'];

    /** @var list<string> */
    public array $editingRoles = [];

    public bool $active = true;

    public function boot(): void
    {
        app(TenantContext::class)->resolve('administration');
    }

    public function mount(): void
    {
        $this->lodgeId = app(TenantContext::class)->resolve('administration')->id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function guard(): Lodge
    {
        return app(TenantContext::class)->assertTenant($this->lodgeId, 'administration');
    }

    public function invite(Invitations $invites): void
    {
        $this->guard();
        try {
            $invites->lodge($this->lodgeId, $this->email, $this->inviteRoles);
        } catch (DeliveryUnavailable $e) {
            $this->addError('email', $e->getMessage());

            return;
        }
        $this->email = '';
        session()->flash('status', 'Richiesta email accettata dal canale configurato. Il destinatario deve accettare l’invito.');
    }

    public function edit(int $id): void
    {
        $lodge = $this->guard();
        $user = $lodge->users()->select(['users.id', 'users.name', 'users.email'])->where('users.id', $id)->firstOrFail();
        $this->editingUser = $user->id;
        $this->editingRoles = [];
        foreach (DB::table('lodge_user_roles')->where('lodge_id', $lodge->id)->where('user_id', $user->id)->pluck('role') as $role) {
            if (is_string($role)) {
                $this->editingRoles[] = $role;
            }
        }
        $this->active = (bool) $user->pivot->getAttribute('is_active');
    }

    public function save(LocalRoles $roles): void
    {
        $this->guard();
        abort_if($this->editingUser === null, 404);
        $roles->update($this->editingUser, $this->editingRoles, $this->active);
        $this->editingUser = null;
        session()->flash('status', 'Autorizzazioni locali aggiornate.');
    }

    public function revokeInvitation(int $id, Invitations $invites): void
    {
        $this->guard();
        $invites->revoke($id);
    }

    public function approve(int $id, Assistance $assistance): void
    {
        $this->guard();
        $assistance->approve($id);
    }

    public function revokeAssistance(int $id, Assistance $assistance): void
    {
        $this->guard();
        $assistance->revoke($id);
    }

    public function render(): View
    {
        $lodge = $this->guard();
        $query = $lodge->users()->select(['users.id', 'users.name', 'users.email'])->orderBy('name');
        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('name', 'like', '%'.mb_substr($this->search, 0, 120).'%')->orWhere('email', 'like', '%'.mb_substr($this->search, 0, 120).'%');
            });
        }

        return view('livewire.identity.access-manager', [
            'lodge' => $lodge, 'members' => $query->paginate(10),
            'rolesByUser' => DB::table('lodge_user_roles')->where('lodge_id', $lodge->id)->get()->groupBy('user_id'),
            'invitations' => Invitation::query()->where('lodge_id', $lodge->id)->whereNull('accepted_at')->whereNull('revoked_at')->orderByDesc('id')->limit(20)->get(),
            'requests' => AssistedAccess::query()->where('lodge_id', $lodge->id)->whereNull('revoked_at')->orderByDesc('id')->limit(20)->get(),
        ])->layout('components.layouts.organization');
    }
}
