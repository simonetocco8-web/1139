<?php

use App\Http\Controllers\Levies\Controller;
use App\Http\Middleware\PrivateMembershipResponse;
use App\Http\Middleware\RequireVerifiedSession;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireVerifiedSession::class, PrivateMembershipResponse::class])->group(function (): void {
    Route::get('/admin/canoni', [Controller::class, 'platform'])->middleware('can:manage-organization')->name('levies.platform');
    Route::post('/admin/canoni', [Controller::class, 'create'])->middleware(['can:manage-organization', 'throttle:identity-sensitive'])->name('levies.create');
    Route::post('/admin/canoni/{program}/condizioni', [Controller::class, 'general'])->whereNumber('program')->middleware(['can:manage-organization', 'throttle:identity-sensitive'])->name('levies.general');
    Route::get('/loggia/canoni', [Controller::class, 'index'])->name('levies.index');
    Route::get('/loggia/canoni/condizioni', [Controller::class, 'conditions'])->name('levies.conditions');
    Route::get('/loggia/canoni/pagamenti', [Controller::class, 'history'])->name('levies.history');
    Route::get('/loggia/canoni/{mode}', [Controller::class, 'index'])->whereIn('mode', ['scadenze'])->name('levies.schedule');
    Route::get('/logge/{lodge}/canoni/{obligation}', [Controller::class, 'detail'])->whereNumber(['lodge', 'obligation'])->name('levies.detail');
    Route::prefix('/logge/{lodge}/canoni')->whereNumber('lodge')->middleware('throttle:identity-sensitive')->group(function (): void {
        Route::post('/genera', [Controller::class, 'generate'])->name('levies.generate');
        Route::post('/programmi/{program}/deroghe', [Controller::class, 'local'])->whereNumber('program')->name('levies.local');
        Route::post('/{obligation}/pagamenti', [Controller::class, 'pay'])->whereNumber('obligation')->name('levies.pay');
    });
});
