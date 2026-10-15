<?php

use App\Http\Controllers\Privacy\Controller;
use App\Http\Middleware\PrivateMembershipResponse;
use App\Http\Middleware\RequireVerifiedSession;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireVerifiedSession::class, PrivateMembershipResponse::class])->group(function (): void {
    Route::get('/logge/{lodge}/privacy', [Controller::class, 'index'])->whereNumber('lodge')->name('privacy.index');
    Route::get('/logge/{lodge}/privacy/persone/{person}/esporta', [Controller::class, 'export'])->whereNumber(['lodge', 'person'])->middleware('throttle:identity-sensitive')->name('privacy.export');
    Route::middleware('throttle:identity-sensitive')->group(function (): void {
        Route::post('/logge/{lodge}/privacy', [Controller::class, 'store'])->whereNumber('lodge')->name('privacy.store');
        Route::post('/logge/{lodge}/privacy/{case}/cancella', [Controller::class, 'erase'])->whereNumber(['lodge', 'case'])->name('privacy.erase');
        Route::post('/logge/{lodge}/privacy/{case}/esito', [Controller::class, 'resolve'])->whereNumber(['lodge', 'case'])->name('privacy.resolve');
    });
});
