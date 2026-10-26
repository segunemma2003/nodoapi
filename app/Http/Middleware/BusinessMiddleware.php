<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\BusinessStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BusinessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user is a business entity, or a staff login attached to one
        if (!$user || !($user instanceof Business || $user instanceof BusinessStaff)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Business account is inactive'
            ], 403);
        }

        if ($user instanceof BusinessStaff && (!$user->business || !$user->business->is_active)) {
            return response()->json([
                'success' => false,
                'message' => 'Business account is inactive'
            ], 403);
        }

        // Check if token has business abilities
        $token = $user->currentAccessToken();
        if (!$token || !$token->can('business:*')) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions'
            ], 403);
        }

        return $next($request);
    }


}
