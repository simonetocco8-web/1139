<?php

namespace App\Domains\Reports;

use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class Runtime
{
    public static function tick(): void
    {
        DB::table('system_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
    }

    /** @return array<string,mixed> */
    public function platform(): array
    {
        VerifiedSession::user();
        Gate::authorize('manage-organization');
        DB::select('SELECT 1');
        $last = Schema::hasTable('system_heartbeats') ? DB::table('system_heartbeats')->where('name', 'scheduler')->value('last_seen_at') : null;
        $pulse = is_string($last) ? CarbonImmutable::parse($last, 'UTC') : null;
        $known = DB::table('migrations')->pluck('migration')->all();
        $files = glob(database_path('migrations/*.php')) ?: [];
        $pending = count(array_diff(array_map(fn ($f) => basename($f, '.php'), $files), $known));
        $org = DB::table('organization_events')->select(['entity_type', 'event', 'occurred_at'])->orderByDesc('id')->limit(8)->get()->map(fn ($e) => ['action' => 'Organizzazione: '.match ((string) $e->event) {
            'created' => 'voce inserita','updated' => 'voce aggiornata','deactivated' => 'voce disattivata',default => 'configurazione aggiornata'
        }, 'date' => CarbonImmutable::parse($e->occurred_at, 'UTC')->setTimezone('Europe/Rome')->format('d/m/Y H:i')])->all();
        $identity = DB::table('identity_events')->whereNull('lodge_id')->whereIn('event', ['invitation.created', 'invitation.accepted', 'invitation.revoked', 'levy.program.created', 'levy.condition.created'])->select(['event', 'occurred_at'])->orderByDesc('id')->limit(8)->get()->map(fn ($e) => ['action' => __('identity.events.'.str_replace('.', '_', $e->event)), 'date' => CarbonImmutable::parse($e->occurred_at, 'UTC')->setTimezone('Europe/Rome')->format('d/m/Y H:i')])->all();

        return ['lodges' => Lodge::query()->where('is_active', true)->whereHas('obedience', fn ($q) => $q->where('is_active', true))->count(), 'users' => User::query()->count(), 'active_users' => User::query()->where('is_active', true)->whereNotNull('activated_at')->count(), 'obediences' => Obedience::query()->count(), 'active_obediences' => Obedience::query()->where('is_active', true)->count(), 'database' => true, 'pending_migrations' => $pending, 'jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'scheduler_last' => $pulse?->setTimezone('Europe/Rome')->format('d/m/Y H:i:s'), 'scheduler_state' => $pulse === null ? 'Non rilevato' : ($pulse->greaterThanOrEqualTo(now('UTC')->subMinutes(5)) ? 'Segnale recente' : 'Segnale in ritardo'), 'storage_writable' => is_writable(storage_path('app/private')), 'activities' => array_merge($org, $identity)];
    }
}
