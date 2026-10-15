<?php

use App\Domains\Membership\Models\ImportBatch;
use App\Domains\Reports\Runtime;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune', ['--model' => ImportBatch::class])->hourly()->withoutOverlapping();

Schedule::command('capitation:generate --queued')->dailyAt('01:15')->withoutOverlapping();

Schedule::command('levy:generate --queued')->dailyAt('01:30')->withoutOverlapping();

Schedule::command('model:prune', ['--model' => App\Domains\Treasury\Models\ImportBatch::class])->hourly()->withoutOverlapping();

Schedule::call(fn () => Runtime::tick())->name('system-heartbeat')->everyMinute()->withoutOverlapping();

Schedule::command('communications:prepare')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('communications:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('operations:check --alert')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('operations:integrity --alert')->dailyAt('02:00')->withoutOverlapping();
// Deletion remains disabled until an approved policy and positive durations are configured.
Schedule::command('operations:prune --apply')->dailyAt('03:00')->when(fn () => is_string(config('operations.policy_reference')) && config('operations.policy_reference') !== '')->withoutOverlapping();
