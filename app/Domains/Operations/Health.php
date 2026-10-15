<?php

namespace App\Domains\Operations;

use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Notifications\Producer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;

final class Health
{
    /** @return list<string> */
    public function issues(bool $deployment = false): array
    {
        DB::select('SELECT 1');
        $issues = [];
        $last = DB::table('system_heartbeats')->where('name', 'scheduler')->value('last_seen_at');
        if (! is_string($last) || CarbonImmutable::parse($last, 'UTC')->lessThan(now('UTC')->subMinutes(5))) {
            $issues[] = 'scheduler_stale';
        }
        if (DB::table('failed_jobs')->exists()) {
            $issues[] = 'queue_failed';
        }
        if (DB::table('jobs')->where('created_at', '<', now('UTC')->subMinutes(max(1, (int) config('operations.queue_max_age_minutes')))->getTimestamp())->exists()) {
            $issues[] = 'queue_delayed';
        }
        if (DB::table('communication_outbox')->where('state', 'uncertain')->exists()) {
            $issues[] = 'communication_uncertain';
        }
        $backup = DB::table('backup_runs')->where('state', 'completed')->orderByDesc('id')->first();
        if ($backup === null || CarbonImmutable::parse($backup->completed_at, 'UTC')->lessThan(now('UTC')->subHours(max(1, (int) config('operations.backup_max_age_hours'))))) {
            $issues[] = 'backup_stale';
        }
        if (DB::table('backup_runs')->whereIn('state', ['running', 'failed'])->where('started_at', '>', now('UTC')->subDay()->format('Y-m-d H:i:s.u'))->exists()) {
            $issues[] = 'backup_attention';
        }
        if ($deployment) {
            if (! is_string(config('app.key')) || strlen(config('app.key')) < 32) {
                $issues[] = 'application_key_invalid';
            }
            if (config('identity.otp_secret') !== null && config('identity.otp_secret') !== '' && (! is_string(config('identity.otp_secret')) || strlen(config('identity.otp_secret')) < 32)) {
                $issues[] = 'otp_secret_invalid';
            }
            if (! User::query()->where('is_platform_admin', true)->where('is_active', true)->whereNotNull('activated_at')->exists()) {
                $issues[] = 'active_platform_admin_missing';
            }
            if (! app()->isProduction()) {
                $issues[] = 'environment_not_production';
            }
            if (config('app.debug')) {
                $issues[] = 'debug_enabled';
            }
            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $issues[] = 'url_not_https';
            }
            if (DB::getDriverName() !== 'mysql') {
                $issues[] = 'database_not_mysql';
            }
            if (config('queue.default') !== 'database' || config('cache.default') !== 'database' || config('session.driver') !== 'database') {
                $issues[] = 'database_drivers_required';
            }
            if (config('queue.connections.database.after_commit') || ! in_array(config('queue.connections.database.connection'), [null, config('database.default')], true) || config('queue.connections.database.table') !== 'jobs') {
                $issues[] = 'queue_connection_unsafe';
            }
            if (! config('session.secure') || ! config('session.http_only') || ! config('session.encrypt') || config('session.same_site') !== 'lax') {
                $issues[] = 'session_configuration';
            }
            if (config('identity.delivery_driver') !== 'configured' || ! app(CodeTransport::class)->available('email')) {
                $issues[] = 'smtp_not_configured';
            }
            $finder = new ExecutableFinder;
            if (in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true) || $finder->find((string) config('operations.mysqldump')) === null || $finder->find('flock') === null || $finder->find('timeout') === null) {
                $issues[] = 'hosting_tools_missing';
            }
            if (DB::getDriverName() === 'mysql' && ! in_array(DB::connection()->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true) && empty(DB::connection()->getConfig('unix_socket'))) {
                $tls = DB::select("SHOW STATUS LIKE 'Ssl_cipher'");
                if (empty($tls[0]->Value)) {
                    $issues[] = 'database_remote_tls_missing';
                }
            }
            if (! extension_loaded('sodium') || ! is_string(config('operations.backup_public_key')) || ! is_readable((string) config('operations.backup_public_key'))) {
                $issues[] = 'backup_configuration';
            }
            if (is_file(public_path('.env')) || is_dir(public_path('vendor')) || is_dir(public_path('app')) || is_link(public_path('storage'))) {
                $issues[] = 'public_root_unsafe';
            }
            if (! is_file(public_path('build/manifest.json'))) {
                $issues[] = 'assets_missing';
            }
            if (! is_string(config('operations.policy_reference')) || config('operations.policy_reference') === '') {
                $issues[] = 'privacy_policy_missing';
            }
            $known = DB::table('migrations')->pluck('migration')->all();
            $files = glob(database_path('migrations/*.php')) ?: [];
            if (array_diff(array_map(fn ($f) => basename($f, '.php'), $files), $known) !== []) {
                $issues[] = 'migrations_pending';
            }
        }

        return $issues;
    }

    public function alert(): int
    {
        $issues = $this->issues();
        $count = 0;
        $producer = app(Producer::class);
        foreach (User::query()->where('is_platform_admin', true)->cursor() as $u) {
            foreach ($issues as $issue) {
                $count += (int) $producer->enqueue(null, $u, 'technical', null, $issue.'|'.now()->toDateString());
            }
        }

        return $count;
    }
}
