<?php

use App\Http\Controllers\Identity\AccessExportController;
use App\Http\Controllers\Identity\AccountController;
use App\Http\Controllers\Identity\InvitationController;
use App\Http\Controllers\Identity\PasswordLinkController;
use App\Http\Controllers\Identity\VerificationController;
use App\Http\Middleware\RequireVerifiedSession;
use App\Livewire\Identity\AccessManager;
use App\Livewire\Identity\AccessReport;
use App\Livewire\Identity\AssistanceManager;
use App\Livewire\Identity\PlatformInvites;
use App\Livewire\Identity\Security;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

Route::get('/login', [AuthenticatedSessionController::class, 'create'])->middleware('guest')->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware(['guest', 'throttle:identity-login'])->block(10, 10)->name('login.store');
Route::get('/otp', [VerificationController::class, 'show'])->name('otp.show');
Route::post('/otp', [VerificationController::class, 'verify'])->middleware('throttle:identity-otp')->block(10, 10)->name('otp.verify');
Route::post('/otp/reinvio', [VerificationController::class, 'resend'])->middleware('throttle:identity-resend')->block(10, 10)->name('otp.resend');
Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->middleware('guest')->name('password.request');
Route::post('/forgot-password', [PasswordLinkController::class, 'store'])->middleware(['guest', 'throttle:identity-public'])->name('password.email');
Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->middleware('guest')->name('password.reset');
Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware(['guest', 'throttle:identity-public'])->name('password.update');
Route::get('/inviti/{token}', [InvitationController::class, 'show'])->middleware('throttle:identity-public')->name('invitation.show');
Route::post('/inviti/{token}', [InvitationController::class, 'accept'])->middleware('throttle:identity-public')->name('invitation.accept');
Route::view('/recupero-secondo-fattore', 'identity.factor-recovery')->middleware('guest')->name('factor.recovery');
Route::post('/recupero-secondo-fattore', [AccountController::class, 'recover'])->middleware(['guest', 'throttle:identity-public'])->name('factor.recover');

Route::middleware(RequireVerifiedSession::class)->group(function (): void {
    Route::livewire('/dashboard', Security::class)->name('dashboard');
    Route::livewire('/account/sicurezza', Security::class)->name('account.security');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::middleware('throttle:identity-sensitive')->group(function (): void {
        Route::post('/account/recapito', [AccountController::class, 'contact'])->name('account.contact');
        Route::post('/account/canale', [AccountController::class, 'channel'])->name('account.channel');
        Route::post('/account/codici-recupero', [AccountController::class, 'codes'])->name('account.codes');
        Route::post('/account/revoca-sessioni', [AccountController::class, 'revoke'])->name('account.revoke');
    });
    Route::livewire('/loggia/accessi', AccessManager::class)->name('tenant.access');
    Route::get('/loggia/accessi/export', [AccessExportController::class, '__invoke'])->middleware('throttle:identity-sensitive')->name('tenant.access.export');
    Route::livewire('/loggia/report-accessi', AccessReport::class)->name('tenant.report');
    Route::livewire('/assistenza', AssistanceManager::class)->name('assistance');
    Route::livewire('/admin/identita', PlatformInvites::class)->middleware('can:manage-organization')->name('platform.invites');
});
