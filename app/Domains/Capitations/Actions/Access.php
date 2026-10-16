<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Models\Installment;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Database\Eloquent\Builder;

final class Access
{
    public function guard(int $lodgeId, string $permission = 'read', bool $staff = true): Lodge
    {
        $lodge = app(TenantContext::class)->assertTenant($lodgeId, $permission);
        if ($staff) {
            abort_unless(app(TenantAccess::class)->fullRead(VerifiedSession::user(), $lodgeId) && app(TenantAccess::class)->allows(VerifiedSession::user(), $lodgeId, 'financial.read'), 403);
        }

        return $lodge;
    }

    public function person(int $lodgeId, int $id, bool $staff = true): Person
    {
        $this->guard($lodgeId, 'read', $staff);
        $q = Person::query()->where('lodge_id', $lodgeId)->whereKey($id);
        if (! $staff) {
            $q->where('user_id', VerifiedSession::user()->id);
        }

        return $q->firstOrFail();
    }

    /** @return Builder<Installment> */
    public function installments(int $lodgeId, ?int $personId = null, bool $staff = true): Builder
    {
        $this->guard($lodgeId, 'read', $staff);
        $q = Installment::query()->where('lodge_id', $lodgeId);
        if ($personId !== null) {
            $this->person($lodgeId, $personId, $staff);
            $q->where('person_id', $personId);
        }
        if (! $staff) {
            $q->whereHas('person', fn ($p) => $p->where('user_id', VerifiedSession::user()->id));
        }

        return $q->withSum('corrections', 'amount')->withSum('allocations', 'amount');
    }
}
