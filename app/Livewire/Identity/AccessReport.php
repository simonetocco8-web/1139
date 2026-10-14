<?php

namespace App\Livewire\Identity;

use App\Domains\Identity\Authorization\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AccessReport extends Component
{
    #[Locked]
    public int $lodgeId = 0;

    public function boot(): void
    {
        app(TenantContext::class)->resolve('reports');
    }

    public function mount(): void
    {
        $this->lodgeId = app(TenantContext::class)->resolve('reports')->id;
    }

    public function render(): View
    {
        $lodge = app(TenantContext::class)->assertTenant($this->lodgeId, 'reports');

        return view('livewire.identity.access-report', [
            'lodge' => $lodge,
            'roles' => DB::table('lodge_user_roles')->join('lodge_user', function ($join): void {
                $join->on('lodge_user.lodge_id', '=', 'lodge_user_roles.lodge_id')->on('lodge_user.user_id', '=', 'lodge_user_roles.user_id');
            })
                ->where('lodge_user.lodge_id', $lodge->id)->where('lodge_user.is_active', true)->selectRaw('role, COUNT(*) as total')->groupBy('role')->get(),
            'events' => DB::table('identity_events')->where('lodge_id', $lodge->id)->orderByDesc('id')->limit(30)->get(['event', 'occurred_at']),
        ])->layout('components.layouts.organization');
    }
}
