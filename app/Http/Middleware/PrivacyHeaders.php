<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivacyHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->hasSession() && is_int($request->session()->get('auth.last_activity_at'))) {
            $response->headers->set('X-Session-Expires-At', (string) ($request->session()->get('auth.last_activity_at') + (int) config('session.lifetime', 30) * 60));
            $response->headers->set('X-Session-Server-Time', (string) now('UTC')->getTimestamp());
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (app()->isProduction()) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
            if ($request->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }
        }

        return $response;
    }
}
