<?php

namespace App\Livewire\Identity;

use App\Domains\Identity\VerifiedSession;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Security extends Component
{
    public function boot(): void
    {
        VerifiedSession::user();
    }

    public function render(): View
    {
        return view('livewire.identity.security', ['account' => VerifiedSession::user()])->layout('components.layouts.organization');
    }
}
