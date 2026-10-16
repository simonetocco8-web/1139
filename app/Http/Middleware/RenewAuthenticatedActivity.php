<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RenewAuthenticatedActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Runs before StartSession persists the response, including Livewire requests.
        if ($request->attributes->get('identity.activity_verified') === true && Auth::check() && is_int(session('auth.last_activity_at'))) {
            session()->put('auth.last_activity_at', now('UTC')->getTimestamp());
        }

        return $response;
    }
}
