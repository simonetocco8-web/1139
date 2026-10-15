<?php

namespace App\Domains\Treasury\Actions;

use App\Domains\Capitations\Actions\Access as LocalAccess;
use App\Domains\Levies\Actions\Access as Locks;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Treasury\Models\Account;

final class Access
{
    public function guard(int $lodgeId, string $permission = 'read'): Lodge
    {
        return app(LocalAccess::class)->guard($lodgeId, $permission);
    }

    public function lock(int $lodgeId, int $obedienceId, string $permission): void
    {
        app(Locks::class)->lock($lodgeId, $obedienceId);
        $this->guard($lodgeId, $permission);
    }

    public function account(int $lodgeId, int $id): Account
    {
        return Account::query()->where('lodge_id', $lodgeId)->whereKey($id)->firstOrFail();
    }
}
