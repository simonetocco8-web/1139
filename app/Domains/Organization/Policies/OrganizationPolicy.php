<?php

namespace App\Domains\Organization\Policies;

use App\Domains\Organization\Models\OrganizationalModel;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, OrganizationalModel $model): bool
    {
        return $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function update(User $user, OrganizationalModel $model): bool
    {
        return $user->is_platform_admin;
    }

    public function delete(User $user, OrganizationalModel $model): bool
    {
        return false;
    }
}
