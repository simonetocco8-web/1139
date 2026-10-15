<?php

use App\Http\Controllers\Treasury\Controller;
use App\Http\Middleware\PrivateMembershipResponse;
use App\Http\Middleware\RequireVerifiedSession;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireVerifiedSession::class, PrivateMembershipResponse::class])->group(function (): void {
    Route::get('/loggia/tesoreria/riepilogo', [Controller::class, 'summary'])->name('treasury.summary');
    Route::get('/loggia/tesoreria', [Controller::class, 'index'])->name('treasury.index');
    Route::get('/loggia/tesoreria/conti', [Controller::class, 'accounts'])->name('treasury.accounts');
    Route::get('/loggia/tesoreria/nuova', [Controller::class, 'form'])->name('treasury.form');
    Route::get('/loggia/tesoreria/esporta/{format}', [Controller::class, 'export'])->whereIn('format', ['csv', 'pdf'])->middleware('throttle:identity-sensitive')->name('treasury.export');
    Route::get('/logge/{lodge}/tesoreria/operazioni/{operation}', [Controller::class, 'detail'])->whereNumber(['lodge', 'operation'])->name('treasury.detail');
    Route::get('/logge/{lodge}/tesoreria/allegati/{attachment}', [Controller::class, 'download'])->whereNumber(['lodge', 'attachment'])->name('treasury.download');
    Route::get('/logge/{lodge}/tesoreria/banche/{account}', [Controller::class, 'bank'])->whereNumber(['lodge', 'account'])->name('treasury.bank');
    Route::prefix('/logge/{lodge}/tesoreria')->whereNumber('lodge')->middleware('throttle:identity-sensitive')->group(function (): void {
        Route::post('/conti', [Controller::class, 'account'])->name('treasury.account');
        Route::post('/categorie', [Controller::class, 'category'])->name('treasury.category');
        Route::post('/operazioni', [Controller::class, 'post'])->name('treasury.post');
        Route::post('/operazioni/{operation}/storno', [Controller::class, 'reverse'])->whereNumber('operation')->name('treasury.reverse');
        Route::post('/operazioni/{operation}/allegati', [Controller::class, 'upload'])->whereNumber('operation')->name('treasury.upload');
        Route::post('/banche/{account}/righe', [Controller::class, 'entry'])->whereNumber('account')->name('treasury.entry');
        Route::post('/banche/{account}/anteprima', [Controller::class, 'preview'])->whereNumber('account')->name('treasury.preview');
        Route::post('/importazioni/{batch}/conferma', [Controller::class, 'confirm'])->whereNumber('batch')->name('treasury.confirm');
        Route::post('/righe/{entry}/abbina', [Controller::class, 'match'])->whereNumber('entry')->name('treasury.match');
        Route::post('/righe/{entry}/approva', [Controller::class, 'approve'])->whereNumber('entry')->name('treasury.approve');
        Route::post('/riconciliazioni/{reconciliation}/rilascia', [Controller::class, 'release'])->whereNumber('reconciliation')->name('treasury.release');
    });
});
