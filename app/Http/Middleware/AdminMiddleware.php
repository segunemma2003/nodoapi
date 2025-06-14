<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user is authenticated and is an admin type user
        if (!$user || !($user instanceof User && $user->isAdmin())) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Admin access required'
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive'
            ], 403);
        }

        // Check if token has admin abilities
        $token = $user->currentAccessToken();
        if (!$token || !$token->can('admin:*')) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions'
            ], 403);
        }

        return $next($request);
    }


}
