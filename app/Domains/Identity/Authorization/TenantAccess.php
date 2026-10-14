<?php

namespace App\Domains\Identity\Authorization;

use App\Domains\Identity\Models\AssistedAccess;
use App\Domains\Organization\Models\Lodge;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TenantAccess
{
    public function allows(User $user, int $lodgeId, string $permission): bool
    {
        if (! $user->is_active || ! in_array($permission, config('identity.permissions'), true)) {
            return false;
        }
        if (! Lodge::query()->whereKey($lodgeId)->where('is_active', true)->whereHas('obedience', fn ($q) => $q->where('is_active', true))->exists()) {
            return false;
        }
        if ($this->ordinary($user, $lodgeId, $permission)) {
            return true;
        }

        return $permission !== 'administration' && $user->is_platform_admin
            && $this->grant($user, $lodgeId, $permission) !== null;
    }

    public function ordinary(User $user, int $lodgeId, string $permission): bool
    {
        $roles = DB::table('lodge_user_roles')->join('lodge_user', function ($join): void {
            $join->on('lodge_user.lodge_id', '=', 'lodge_user_roles.lodge_id')->on('lodge_user.user_id', '=', 'lodge_user_roles.user_id');
        })->where('lodge_user.user_id', $user->id)->where('lodge_user.lodge_id', $lodgeId)->where('lodge_user.is_active', true)->pluck('role');
        foreach ($roles as $role) {
            if (in_array($permission, config('identity.roles.'.$role, []), true)) {
                return true;
            }
        }

        return false;
    }

    public function fullRead(User $user, int $lodgeId): bool
    {
        return $this->ordinary($user, $lodgeId, 'create') || ($user->is_platform_admin && $this->grant($user, $lodgeId, 'read') !== null);
    }

    public function grant(User $user, int $lodgeId, string $permission): ?AssistedAccess
    {
        return AssistedAccess::query()->where('lodge_id', $lodgeId)->where('requester_id', $user->id)
            ->whereNotNull('approved_at')->whereNotNull('approved_by')->whereColumn('approved_by', '!=', 'requester_id')
            ->whereNull('revoked_at')->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->whereJsonContains('permissions', $permission)->first();
    }
}
