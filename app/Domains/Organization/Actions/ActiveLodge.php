<?php

namespace App\Domains\Organization\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ActiveLodge
{
    /** @return Builder<Lodge> */
    public function authorized(User $user): Builder
    {
        return Lodge::query()->select(['lodges.id', 'lodges.obedience_id', 'lodges.name', 'lodges.number', 'lodges.is_active'])->where('is_active', true)
            ->whereHas('obedience', fn (Builder $q) => $q->where('is_active', true))
            ->where(function (Builder $query) use ($user): void {
                $query->where(function (Builder $q) use ($user): void {
                    $q->whereHas('users', fn (Builder $u) => $u->where('users.id', $user->id)->where('lodge_user.is_active', true))
                        ->whereExists(fn ($roles) => $roles->selectRaw('1')->from('lodge_user_roles')->whereColumn('lodge_user_roles.lodge_id', 'lodges.id')->where('user_id', $user->id));
                });
                if ($user->is_platform_admin) {
                    $query->orWhereExists(fn ($grants) => $grants->selectRaw('1')->from('assisted_access_grants')
                        ->whereColumn('lodge_id', 'lodges.id')->where('requester_id', $user->id)
                        ->whereNotNull('approved_at')->whereNotNull('approved_by')->whereColumn('approved_by', '!=', 'requester_id')
                        ->whereNull('revoked_at')->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u')));
                }
            });
    }

    public function select(int $id): Lodge
    {
        $lodge = $this->authorized(VerifiedSession::user())->findOrFail($id);
        session()->put('active_lodge_id', $lodge->id);
        Audit::record('tenant.selected', VerifiedSession::user()->id, $lodge->id);

        return $lodge;
    }

    public function current(): ?Lodge
    {
        $id = session('active_lodge_id');
        if (! is_int($id)) {
            return null;
        }
        $lodge = $this->authorized(VerifiedSession::user())->find($id);
        if ($lodge === null) {
            session()->forget('active_lodge_id');
        }

        return $lodge;
    }
}
