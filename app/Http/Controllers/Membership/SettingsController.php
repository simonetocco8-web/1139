<?php

namespace App\Http\Controllers\Membership;

use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Membership\Actions\LodgeSettings;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController
{
    public function show(LodgeSettings $settings): View
    {
        $active = app(TenantContext::class)->resolve('administration');
        $lodge = Lodge::query()->with(['obedience', 'rite', 'currentAssignments.structure'])->findOrFail($active->id);
        $officers = DB::table('lodge_user_roles')->join('lodge_user', fn ($j) => $j->on('lodge_user.lodge_id', '=', 'lodge_user_roles.lodge_id')->on('lodge_user.user_id', '=', 'lodge_user_roles.user_id'))->join('users', 'users.id', '=', 'lodge_user.user_id')
            ->where('lodge_user.lodge_id', $lodge->id)->where('lodge_user.is_active', true)->where('users.is_active', true)->whereIn('role', ['administrator', 'treasurer', 'secretary', 'worshipful_master'])->select(['users.name', 'role'])->orderBy('users.name')->get();

        return view('membership.settings', ['lodge' => $lodge, 'revision' => $settings->revision($lodge), 'officers' => $officers]);
    }

    public function save(Request $request, int $lodge, LodgeSettings $settings): RedirectResponse
    {
        app(TenantContext::class)->assertTenant($lodge, 'administration');
        $request->validate(['confirmation' => ['required', 'accepted']]);
        $settings->save($lodge, $request->all());

        return redirect()->route('members.settings')->with('status', 'Impostazioni aggiornate.');
    }
}
