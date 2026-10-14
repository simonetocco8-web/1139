<?php

use App\Http\Controllers\Membership\ExportController;
use App\Http\Controllers\Membership\ImportController;
use App\Http\Controllers\Membership\MemberController;
use App\Http\Controllers\Membership\SettingsController;
use App\Http\Middleware\PrivateMembershipResponse;
use App\Http\Middleware\RequireVerifiedSession;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireVerifiedSession::class, PrivateMembershipResponse::class])->group(function (): void {
    Route::get('/loggia/fratelli', [MemberController::class, 'index'])->name('members.index');
    Route::get('/loggia/fratelli/export', ExportController::class)->middleware('throttle:identity-sensitive')->name('members.export');
    Route::get('/loggia/fratelli/importazione', [ImportController::class, 'index'])->name('members.import');
    Route::get('/loggia/fratelli/modello-csv', [ImportController::class, 'template'])->name('members.template');
    Route::get('/loggia/impostazioni', [SettingsController::class, 'show'])->name('members.settings');
    Route::get('/loggia/posizione-personale', [MemberController::class, 'position'])->name('members.position');
    Route::prefix('/logge/{lodge}')->whereNumber('lodge')->group(function (): void {
        Route::get('/fratelli/nuovo', [MemberController::class, 'create'])->name('members.create');
        Route::get('/fratelli/{person}', [MemberController::class, 'show'])->whereNumber('person')->name('members.show');
        Route::get('/importazioni/{token}', [ImportController::class, 'show'])->name('members.import.preview');
        Route::middleware('throttle:identity-sensitive')->group(function (): void {
            Route::post('/fratelli', [MemberController::class, 'store'])->name('members.store');
            Route::post('/fratelli/{person}/modifica', [MemberController::class, 'update'])->whereNumber('person')->name('members.update');
            Route::post('/fratelli/{person}/iscrizioni', [MemberController::class, 'enrollment'])->whereNumber('person')->name('members.enrollment');
            Route::post('/fratelli/{person}/iscrizioni/{enrollment}/disattiva', [MemberController::class, 'deactivate'])->whereNumber(['person', 'enrollment'])->name('members.deactivate');
            Route::post('/fratelli/{person}/invito', [MemberController::class, 'invite'])->whereNumber('person')->name('members.invite');
            Route::post('/importazioni', [ImportController::class, 'preview'])->name('members.import.upload');
            Route::post('/importazioni/{token}/conferma', [ImportController::class, 'confirm'])->name('members.import.confirm');
            Route::post('/impostazioni', [SettingsController::class, 'save'])->name('members.settings.save');
        });
    });
});
