<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Domains\Identity\Actions\BeginOtpLogin;
use App\Domains\Identity\Actions\OtpService;
use App\Domains\Identity\Secret;
use App\Http\Controllers\Identity\ResetCompletedResponse;
use App\Http\Controllers\Identity\UniformResetFailure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register only the explicitly audited Fortify routes in routes/identity.php.
        Fortify::ignoreRoutes();
        $this->app->bind(FailedPasswordResetResponse::class, UniformResetFailure::class);
        $this->app->bind(PasswordResetResponse::class, ResetCompletedResponse::class);
    }

    public function boot(): void
    {
        Fortify::authenticateThrough(fn () => [BeginOtpLogin::class]);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => view('identity.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('identity.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('identity.reset-password', ['token' => $request->route('token'), 'email' => $request->query('email', '')]));
        RateLimiter::for('identity-login', fn (Request $request) => [
            Limit::perMinute(5)->by('account:'.Secret::digest('limit', mb_strtolower(trim((string) $request->input('email'))))),
            Limit::perMinute(20)->by('ip:'.Secret::digest('ip', (string) $request->ip())),
        ]);
        foreach (['identity-otp' => 10, 'identity-resend' => 5] as $name => $maximum) {
            RateLimiter::for($name, function (Request $request) use ($name, $maximum): array {
                $pending = app(OtpService::class)->pending();
                $account = $pending->user_id ?? session('otp.nonce', 'none');

                return [
                    Limit::perMinutes($name === 'identity-resend' ? 10 : 1, $maximum)->by('account:'.Secret::digest('limit', (string) $account)),
                    Limit::perMinutes($name === 'identity-resend' ? 10 : 1, $maximum * 3)->by('ip:'.Secret::digest('ip', (string) $request->ip())),
                ];
            });
        }
        RateLimiter::for('identity-public', fn (Request $request) => [
            Limit::perMinute(5)->by('account:'.Secret::digest('limit', mb_strtolower(trim((string) $request->input('email', $request->route('token', $request->session()->getId())))))),
            Limit::perMinute(10)->by('ip:'.Secret::digest('ip', (string) $request->ip())),
        ]);
        RateLimiter::for('identity-sensitive', fn (Request $request) => [
            Limit::perMinute(5)->by('account:'.($request->user()->id ?? 'none')),
            Limit::perMinute(10)->by('ip:'.Secret::digest('ip', (string) $request->ip())),
        ]);
    }
}
