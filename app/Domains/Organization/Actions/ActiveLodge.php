<?php

namespace App\Domains\Organization\Actions;

use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ActiveLodge
{
    /** @return Builder<Lodge> */
    public function authorized(User $user): Builder
    {
        return Lodge::query()->where('is_active', true)
            ->whereHas('obedience', fn (Builder $q) => $q->where('is_active', true))
            ->whereHas('users', fn (Builder $q) => $q->where('users.id', $user->id)->where('lodge_user.is_active', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('lodge_user_roles')->whereColumn('lodge_user_roles.lodge_id', 'lodges.id')->where('user_id', $user->id));
    }

    public function select(int $id): Lodge
    {
        $lodge = $this->authorized(VerifiedSession::user())->findOrFail($id);
        session()->put('active_lodge_id', $lodge->id);

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
