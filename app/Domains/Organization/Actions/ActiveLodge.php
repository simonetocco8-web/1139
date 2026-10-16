<?php

namespace App\Domains\Organization\Actions;

use App\Domains\Identity\Actions\SingleLodgeMembership;
use App\Domains\Identity\Audit;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        $user = VerifiedSession::user();
        $this->assertSingleLocal($user);
        $lodge = $this->authorized($user)->findOrFail($id);
        session()->put('active_lodge_id', $lodge->id);
        Audit::record('tenant.selected', VerifiedSession::user()->id, $lodge->id);

        return $lodge;
    }

    public function current(): ?Lodge
    {
        $user = VerifiedSession::user();
        $this->assertSingleLocal($user);
        $available = $this->authorized($user);
        $id = session('active_lodge_id');
        if (! is_int($id)) {
            $only = (clone $available)->limit(2)->get();
            if ($only->count() === 1) {
                session()->put('active_lodge_id', $only->first()->id);

                return $only->first();
            }

            return null;
        }
        $lodge = $available->find($id);
        if ($lodge === null) {
            session()->forget('active_lodge_id');
        }

        return $lodge;
    }

    public function canChoose(): bool
    {
        $user = VerifiedSession::user();

        return ! app(SingleLodgeMembership::class)->localOnly($user) && $this->authorized($user)->count() > 1;
    }

    private function assertSingleLocal(User $user): void
    {
        if (app(SingleLodgeMembership::class)->localOnly($user)) {
            $count = DB::table('lodge_user_roles as r')->join('lodge_user as m', fn ($j) => $j->on('m.user_id', '=', 'r.user_id')->on('m.lodge_id', '=', 'r.lodge_id'))->where('r.user_id', $user->id)->where('m.is_active', true)->distinct()->count('r.lodge_id');
            abort_if($count > 1, 403, 'Associazione a più logge non consentita per un utente locale. Rivolgiti all’amministratore per correggere gli accessi.');
        }
    }
}
