<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API middleware groups
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Register custom middleware aliases
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'business' => \App\Http\Middleware\BusinessMiddleware::class,
            'auth.token' => \App\Http\Middleware\EnsureTokenIsValid::class,
        ]);

        // Global middleware
        $middleware->use([
            // \Illuminate\Http\Middleware\TrustHosts::class,
            \Illuminate\Http\Middleware\TrustProxies::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Http\Middleware\ValidatePostSize::class,
            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        // Sanctum configuration
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
