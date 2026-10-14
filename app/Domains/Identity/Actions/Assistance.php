<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\Models\AssistedAccess;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Assistance
{
    /** @param list<string> $permissions */
    public function request(int $lodgeId, string $reason, array $permissions, int $minutes): AssistedAccess
    {
        $user = VerifiedSession::recent();
        abort_unless($user->is_platform_admin, 403);
        $data = Validator::make(compact('reason', 'permissions', 'minutes'), ['reason' => ['required', 'string', 'min:10', 'max:1000'], 'permissions' => ['required', 'array', 'min:1'], 'permissions.*' => ['string', 'distinct', Rule::in(['read', 'create', 'update', 'reverse', 'export', 'reports'])], 'minutes' => ['required', 'integer', 'min:1', 'max:60']])->validate();
        $lodge = Lodge::query()->whereKey($lodgeId)->where('is_active', true)->firstOrFail();

        return DB::transaction(function () use ($user, $lodge, $data): AssistedAccess {
            $grant = AssistedAccess::query()->create(['lodge_id' => $lodge->id, 'requester_id' => $user->id, 'reason' => $data['reason'], 'permissions' => $data['permissions'], 'duration_minutes' => $data['minutes']]);
            Audit::record('assistance.requested', $user->id, $lodge->id, metadata: ['grant_id' => $grant->id]);

            return $grant;
        }, 3);
    }

    public function approve(int $id): void
    {
        $user = VerifiedSession::recent();
        $lodge = app(TenantContext::class)->resolve('administration');
        abort_unless(app(TenantAccess::class)->ordinary($user, $lodge->id, 'administration'), 403);
        DB::transaction(function () use ($user, $lodge, $id): void {
            Lodge::query()->whereKey($lodge->id)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->assertTenant($lodge->id, 'administration');
            abort_unless(app(TenantAccess::class)->ordinary(VerifiedSession::user(), $lodge->id, 'administration'), 403);
            $grant = AssistedAccess::query()->where('lodge_id', $lodge->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($grant->requester_id === $user->id || $grant->approved_at !== null || $grant->revoked_at !== null, 403);
            $grant->forceFill(['approved_by' => $user->id, 'approved_at' => now('UTC'), 'expires_at' => now('UTC')->addMinutes($grant->duration_minutes)])->save();
            Audit::record('assistance.approved', $user->id, $lodge->id, $grant->requester_id, ['grant_id' => $grant->id]);
        }, 3);
    }

    public function revoke(int $id): void
    {
        $user = VerifiedSession::user();
        $selected = session('active_lodge_id');
        $local = is_int($selected) && app(TenantAccess::class)->allows($user, $selected, 'administration')
            && app(TenantAccess::class)->ordinary($user, $selected, 'administration');
        // Scope before loading the encrypted reason or any other tenant data.
        $eligible = AssistedAccess::query()->whereKey($id)->where(function ($query) use ($user, $selected, $local): void {
            $query->where('requester_id', $user->id);
            if ($local) {
                $query->orWhere('lodge_id', $selected);
            }
        });
        $initial = $eligible->firstOrFail();
        DB::transaction(function () use ($user, $eligible, $initial): void {
            Lodge::query()->whereKey($initial->lodge_id)->lockForUpdate()->firstOrFail();
            $grant = (clone $eligible)->lockForUpdate()->firstOrFail();
            if ($grant->requester_id !== $user->id) {
                app(TenantContext::class)->assertTenant($grant->lodge_id, 'administration');
                abort_unless(app(TenantAccess::class)->ordinary($user, $grant->lodge_id, 'administration'), 403);
            }
            if ($grant->revoked_at !== null) {
                return;
            }
            $grant->revoked_at = now('UTC');
            $grant->save();
            Audit::record('assistance.revoked', $user->id, $grant->lodge_id, $grant->requester_id, ['grant_id' => $grant->id]);
        }, 3);
    }
}
