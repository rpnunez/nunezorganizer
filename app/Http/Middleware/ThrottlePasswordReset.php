<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Limits Fortify's reset POST routes, which do not declare a route throttle. */
class ThrottlePasswordReset
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('password.email', 'password.update')) {
            $ip = (string) $request->ip();
            $address = Str::lower((string) $request->input('email', ''));
            $key = 'password-reset|'.$ip.'|'.hash('sha256', $address);
            $ipKey = 'password-reset-ip|'.$ip;
            abort_if(RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 20), 429);
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ipKey, 60);
        }

        return $next($request);
    }
}
