<?php

namespace App\Domains\Levies\Actions;

use App\Domains\Capitations\Actions\Access as TenantAccess;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use Illuminate\Database\Eloquent\Builder;

final class Access
{
    public function guard(int $lodgeId, string $permission = 'read'): Lodge
    {
        return app(TenantAccess::class)->guard($lodgeId, $permission);
    }

    // Lock order shared with OrganizationActions: obedience, then lodge.
    public function lock(int $lodgeId, int $obedienceId): Lodge
    {
        // Resolve immutable obedience ID before entering the transaction. A plain
        // lookup here would open a stale REPEATABLE READ snapshot before waiting.
        Obedience::query()->whereKey($obedienceId)->lockForUpdate()->firstOrFail();

        return Lodge::query()->whereKey($lodgeId)->where('obedience_id', $obedienceId)->lockForUpdate()->firstOrFail();
    }

    /** @return Builder<Obligation> */
    public function obligations(int $lodgeId, string $permission = 'read'): Builder
    {
        $this->guard($lodgeId, $permission);

        return Obligation::query()->where('lodge_id', $lodgeId)->withSum('payments', 'amount');
    }
}
