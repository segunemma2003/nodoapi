<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    protected function redirectTo(Request $request): ?string
    {
        // For API requests, return null to prevent redirects
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // If you have web routes, you'd return a login route here
        // For API-only apps, this shouldn't be reached
        return null;
    }
}
