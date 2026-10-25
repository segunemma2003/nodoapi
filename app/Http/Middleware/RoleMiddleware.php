<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after the `admin` middleware, which already confirmed the user is an
 * active admin-type user. This layer narrows access further by role.
 *
 * super_admin always passes, regardless of the roles listed. Usage:
 *   ->middleware('role:admin,finance_admin')  // super_admin, admin, or finance_admin
 *   ->middleware('role')                      // super_admin only
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->role === 'super_admin') {
            return $next($request);
        }

        if (!$user instanceof User || !in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions for this action',
            ], 403);
        }

        return $next($request);
    }
}
