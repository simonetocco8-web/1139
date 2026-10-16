<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LocalRoles
{
    /** @param list<string> $roles */
    public function update(int $userId, array $roles, bool $active): void
    {
        $actor = VerifiedSession::recent();
        $lodge = app(TenantContext::class)->resolve('administration');
        Validator::make(['roles' => $roles], ['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', 'distinct', Rule::in(array_keys(config('identity.roles')))]])->validate();
        DB::transaction(function () use ($actor, $lodge, $userId, $roles, $active): void {
            Lodge::query()->whereKey($lodge->id)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->assertTenant($lodge->id, 'administration');
            DB::table('lodge_user')->where('lodge_id', $lodge->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            app(SingleLodgeMembership::class)->assertAssignment($userId, $lodge->id, $roles, $active);
            $wasAdmin = DB::table('lodge_user_roles')->where('lodge_id', $lodge->id)->where('user_id', $userId)->whereIn('role', ['administrator', 'worshipful_master'])->exists();
            if ($wasAdmin && (! $active || ! array_intersect(['administrator', 'worshipful_master'], $roles)) && ! $this->otherAdmin($lodge->id, $userId)) {
                throw ValidationException::withMessages(['roles' => 'Conservare almeno un amministratore attivo per la loggia.']);
            }
            DB::table('lodge_user_roles')->where('lodge_id', $lodge->id)->where('user_id', $userId)->delete();
            foreach ($roles as $role) {
                DB::table('lodge_user_roles')->insert(['lodge_id' => $lodge->id, 'user_id' => $userId, 'role' => $role]);
            }
            DB::table('lodge_user')->where('lodge_id', $lodge->id)->where('user_id', $userId)->update(['is_active' => $active]);
            Audit::record('roles.updated', $actor->id, $lodge->id, $userId, ['roles' => $roles, 'active' => $active]);
        }, 3);
    }

    private function otherAdmin(int $lodgeId, int $except): bool
    {
        return DB::table('lodge_user_roles')->join('lodge_user', function ($join): void {
            $join->on('lodge_user.lodge_id', '=', 'lodge_user_roles.lodge_id')->on('lodge_user.user_id', '=', 'lodge_user_roles.user_id');
        })
            ->join('users', 'users.id', '=', 'lodge_user.user_id')->where('lodge_user_roles.lodge_id', $lodgeId)->where('lodge_user_roles.user_id', '!=', $except)
            ->whereIn('lodge_user_roles.role', ['administrator', 'worshipful_master'])->where('lodge_user.is_active', true)->where('users.is_active', true)->exists();
    }
}
