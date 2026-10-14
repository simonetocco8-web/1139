<?php

namespace App\Http\Middleware;

use App\Domains\Identity\VerifiedSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        VerifiedSession::user();

        return $next($request);
    }
}
