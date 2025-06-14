<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Handle unauthenticated users for API requests
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // For API requests, return JSON response instead of redirecting
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated - Please provide a valid token'
            ], 401);
        }

        // For web requests (if any), you would redirect to login
        // Since this is API-only, this shouldn't be reached
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated'
        ], 401);
    }
}
