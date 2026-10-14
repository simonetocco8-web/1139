<?php

namespace App\Livewire\Identity;

use App\Domains\Identity\Actions\Invitations;
use App\Domains\Identity\Delivery\DeliveryUnavailable;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PlatformInvites extends Component
{
    public string $email = '';

    public ?int $targetLodge = null;

    public function boot(): void
    {
        abort_unless(VerifiedSession::user()->is_platform_admin, 403);
    }

    public function invitePlatform(Invitations $invites): void
    {
        try {
            $invites->platform($this->email);
        } catch (DeliveryUnavailable $e) {
            $this->addError('email', $e->getMessage());

            return;
        }
        $this->email = '';
        session()->flash('status', 'Richiesta email accettata dal canale configurato.');
    }

    public function inviteInitialAdministrator(Invitations $invites): void
    {
        abort_if($this->targetLodge === null, 422);
        try {
            $invites->lodge($this->targetLodge, $this->email, ['administrator'], bootstrap: true);
        } catch (DeliveryUnavailable $e) {
            $this->addError('email', $e->getMessage());

            return;
        }
        $this->email = '';
        session()->flash('status', 'Invito iniziale predisposto. Nessun accesso ai contenuti è stato concesso al Super User.');
    }

    public function render(): View
    {
        abort_unless(VerifiedSession::user()->is_platform_admin, 403);

        return view('livewire.identity.platform-invites', ['lodges' => Lodge::query()->where('is_active', true)->orderBy('name')->get()])->layout('components.layouts.organization');
    }
}
