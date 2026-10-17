<?php

use App\Http\Middleware\PrivacyHeaders;
use App\Http\Middleware\RenewAuthenticatedActivity;
use App\Http\Middleware\RequireSecureTransport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Liveness only: no sessions, database probe or private diagnostics.
            Route::get('/up', fn () => response()->json(['status' => 'ok']))->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(PrivacyHeaders::class);
        $middleware->redirectUsersTo('/dashboard');
        $middleware->web(append: [RequireSecureTransport::class, RenewAuthenticatedActivity::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpExceptionInterface $error, Request $request) {
            if (($error->getHeaders()['X-Session-Expired'] ?? null) === '1' && ! $request->expectsJson()) {
                return response()->view('identity.session-expired', [], $error->getStatusCode(), $error->getHeaders());
            }

            return null;
        });
        $exceptions->report(function (QueryException $error): bool {
            Log::error('Operazione di persistenza non completata.', ['sql_state' => $error->errorInfo[0] ?? 'unknown']);

            return false;
        });
        $exceptions->report(function (Throwable $error): ?bool {
            if (! app()->isProduction()) {
                return null;
            }
            Log::error('Errore applicativo. Consultare il registro tecnico autorizzato.', ['exception_class' => $error::class]);

            return false;
        });
        $exceptions->dontFlash(['password', 'password_confirmation', 'code', 'recovery_code', 'token', 'target']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
