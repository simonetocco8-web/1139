<?php

use App\Http\Controllers\Notifications\Controller;
use App\Http\Middleware\PrivateMembershipResponse;
use App\Http\Middleware\RequireVerifiedSession;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireVerifiedSession::class, PrivateMembershipResponse::class])->group(function (): void {
    Route::get('/logge/{lodge}/notifiche', [Controller::class, 'index'])->whereNumber('lodge')->name('communications.index');
    Route::get('/logge/{lodge}/notifiche/{notification}', [Controller::class, 'show'])->whereNumber(['lodge', 'notification'])->name('communications.show');
    Route::get('/admin/notifiche', [Controller::class, 'index'])->middleware('can:manage-organization')->name('communications.platform');
    Route::middleware('throttle:identity-sensitive')->group(function (): void {
        Route::post('/logge/{lodge}/notifiche/preferenze', [Controller::class, 'preference'])->whereNumber('lodge')->name('communications.preference');
        Route::post('/admin/notifiche/preferenze', [Controller::class, 'preference'])->middleware('can:manage-organization')->name('communications.platform.preference');
        Route::post('/logge/{lodge}/notifiche/regole', [Controller::class, 'rule'])->whereNumber('lodge')->name('communications.rule');
        Route::post('/logge/{lodge}/notifiche/componi', [Controller::class, 'compose'])->whereNumber('lodge')->name('communications.compose');
        Route::post('/logge/{lodge}/notifiche/{notification}/riprova', [Controller::class, 'retryLocal'])->whereNumber(['lodge', 'notification'])->name('communications.retry');
        Route::post('/admin/notifiche/{notification}/riprova', [Controller::class, 'retry'])->whereNumber('notification')->middleware('can:manage-organization')->name('communications.platform.retry');
    });
});
