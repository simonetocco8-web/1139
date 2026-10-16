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
        session()->put('auth.last_activity_at', now('UTC')->getTimestamp());
        request()->attributes->set('identity.activity_verified', true);

        return $next($request);
    }
}
