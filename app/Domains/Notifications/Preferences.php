<?php

namespace App\Domains\Notifications;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Preferences
{
    public static function scope(?int $lodge): string
    {
        return $lodge === null ? 'platform' : 'lodge:'.$lodge;
    }

    public function eligible(User $u, ?int $lodge, string $type): bool
    {
        if (! $u->is_active || $u->activated_at === null || $u->email_verified_at === null || ! array_key_exists($type, config('communications.types'))) {
            return false;
        }
        if ($lodge === null) {
            return $type === 'technical' && $u->is_platform_admin;
        }
        if ($type === 'technical' || ! Lodge::query()->whereKey($lodge)->where('is_active', true)->whereHas('obedience', fn ($q) => $q->where('is_active', true))->exists()) {
            return false;
        }
        $roles = DB::table('lodge_user_roles as r')->join('lodge_user as m', fn ($j) => $j->on('m.lodge_id', '=', 'r.lodge_id')->on('m.user_id', '=', 'r.user_id'))->where('m.lodge_id', $lodge)->where('m.user_id', $u->id)->where('m.is_active', true)->pluck('r.role');
        foreach ($roles as $role) {
            if (in_array($type, config('communications.roles.'.$role, []), true) && app(TenantAccess::class)->ordinary($u, $lodge, 'read')) {
                if (str_starts_with($type, 'levy_') && ! app(TenantAccess::class)->ordinary($u, $lodge, 'reports')) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /** @return array{enabled:bool,channel:string} */
    public function delivery(User $u, ?int $lodge, string $type): array
    {
        $row = DB::table('communication_preferences')->where('scope', self::scope($lodge))->where('user_id', $u->id)->where('type', $type)->first();
        $rule = $lodge === null || DB::table('communication_rules')->where('lodge_id', $lodge)->where('type', $type)->where('enabled', true)->exists();

        return ['enabled' => config('communications.enabled') && $rule && ($row === null || (bool) $row->enabled) && $this->eligible($u, $lodge, $type), 'channel' => $row->channel ?? 'email'];
    }

    public function save(?int $lodge, string $type, bool $enabled, string $channel): void
    {
        $u = VerifiedSession::user();
        if ($lodge === null) {
            Gate::authorize('manage-organization');
        } else {
            app(TenantContext::class)->assertTenant($lodge, 'read');
        }
        abort_unless($this->eligible($u, $lodge, $type), 403);
        Validator::make(['channel' => $channel], ['channel' => ['required', Rule::in(['email', 'sms'])]])->validate();
        abort_if($channel === 'sms' && ($u->phone === null || $u->phone_verified_at === null), 422, 'Verifica prima il numero nelle impostazioni di sicurezza.');
        DB::table('communication_preferences')->updateOrInsert(['scope' => self::scope($lodge), 'user_id' => $u->id, 'type' => $type], ['enabled' => $enabled, 'channel' => $channel]);
        Audit::record('communications.preference', $u->id, $lodge, metadata: ['type' => $type, 'enabled' => $enabled]);
    }
}
