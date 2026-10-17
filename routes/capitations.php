<?php

use App\Http\Controllers\Capitations\Controller;
use App\Http\Middleware\PrivateMembershipResponse;
use App\Http\Middleware\RequireVerifiedSession;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireVerifiedSession::class, PrivateMembershipResponse::class])->group(function (): void {
    Route::get('/loggia/capitazioni', [Controller::class, 'index'])->name('capitations.index');
    Route::get('/loggia/capitazioni/personale', [Controller::class, 'personal'])->name('capitations.personal');
    Route::get('/loggia/capitazioni/{mode}', [Controller::class, 'report'])->whereIn('mode', ['scadenze', 'morosi', 'riepilogo', 'future'])->name('capitations.report');
    Route::get('/logge/{lodge}/capitazioni/fratelli/{person}', [Controller::class, 'person'])->whereNumber(['lodge', 'person'])->name('capitations.person');
    Route::prefix('/logge/{lodge}/capitazioni')->whereNumber('lodge')->middleware('throttle:identity-sensitive')->group(function (): void {
        Route::post('/piani', [Controller::class, 'plan'])->name('capitations.plan');
        Route::post('/piani/{plan}/versioni', [Controller::class, 'revise'])->whereNumber('plan')->name('capitations.revise');
        Route::get('/piani/{plan}/anticipate', [Controller::class, 'advancePreview'])->whereNumber('plan')->name('capitations.advance.preview');
        Route::post('/anticipate/conferma', [Controller::class, 'advanceConfirm'])->name('capitations.advance.confirm');
        Route::post('/genera', [Controller::class, 'generate'])->name('capitations.generate');
        Route::post('/fratelli/{person}/regole', [Controller::class, 'rule'])->whereNumber('person')->name('capitations.rule');
        Route::post('/fratelli/{person}/incassi', [Controller::class, 'receive'])->whereNumber('person')->name('capitations.receive');
        Route::post('/fratelli/{person}/incassi/{receipt}/imputa', [Controller::class, 'allocate'])->whereNumber(['person', 'receipt'])->name('capitations.allocate');
        Route::post('/rate/{installment}/rettifiche', [Controller::class, 'correction'])->whereNumber('installment')->name('capitations.correct');
        Route::post('/imputazioni/{allocation}/annulla', [Controller::class, 'reverse'])->whereNumber('allocation')->name('capitations.reverse');
    });
});
