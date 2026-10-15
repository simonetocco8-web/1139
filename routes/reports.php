<?php

use App\Domains\Reports\Builder;
use App\Http\Controllers\Reports\Controller;
use App\Http\Middleware\PrivateMembershipResponse;
use App\Http\Middleware\RequireVerifiedSession;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireVerifiedSession::class, PrivateMembershipResponse::class])->group(function (): void {
    Route::get('/dashboard', [Controller::class, 'index'])->name('dashboard');
    Route::get('/admin/panoramica', [Controller::class, 'platform'])->middleware('can:manage-organization')->name('reports.platform');
    Route::get('/logge/{lodge}/panoramica', [Controller::class, 'tenant'])->whereNumber('lodge')->name('reports.dashboard');
    Route::get('/logge/{lodge}/report/{kind}', [Controller::class, 'show'])->whereNumber('lodge')->whereIn('kind', array_keys(Builder::TITLES))->name('reports.show');
    Route::get('/logge/{lodge}/report/{kind}/esporta/{format}', [Controller::class, 'export'])->whereNumber('lodge')->whereIn('kind', array_keys(Builder::TITLES))->whereIn('format', ['csv', 'pdf'])->middleware('throttle:identity-sensitive')->name('reports.export');
});
