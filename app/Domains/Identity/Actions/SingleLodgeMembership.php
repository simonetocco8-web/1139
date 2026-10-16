<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SingleLodgeMembership
{
    public function localOnly(User $user): bool
    {
        return ! $user->is_platform_admin;
    }

    /** @param list<string> $roles */
    public function assertAssignment(int $userId, int $lodgeId, array $roles, bool $active = true): void
    {
        // Caller holds the account row lock: assignments in distinct lodges serialize here.
        if (! $active || $roles === []) {
            return;
        }
        $other = DB::table('lodge_user_roles as r')->join('lodge_user as m', fn ($j) => $j->on('m.user_id', '=', 'r.user_id')->on('m.lodge_id', '=', 'r.lodge_id'))->where('r.user_id', $userId)->where('m.is_active', true)->where('r.lodge_id', '!=', $lodgeId)->lockForUpdate()->first(['r.user_id']);
        if ($other) {
            throw ValidationException::withMessages(['roles' => 'L’utente è già associato a una loggia. Occorre risolvere la precedente associazione prima di assegnarne un’altra.']);
        }
    }
}
