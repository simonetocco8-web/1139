<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecureTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(app()->isProduction() && ! $request->isSecure(), 403, 'È richiesta una connessione HTTPS.');

        return $next($request);
    }
}
