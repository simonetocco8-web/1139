<?php

use App\Domains\Membership\Models\ImportBatch;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune', ['--model' => ImportBatch::class])->hourly()->withoutOverlapping();

Schedule::command('capitation:generate')->dailyAt('01:15')->withoutOverlapping();
