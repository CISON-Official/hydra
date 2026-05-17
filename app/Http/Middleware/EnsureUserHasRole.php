<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // 1. Ensure the user is actually authenticated
        if (!$request->user()) {
            return response()->json([
                'error' => 'Unauthenticated.',
                'message' => 'You must log in to access this resource.'
            ], 401);
        }

        // 2. Check if the user's role matches any of the allowed roles
        // (Assumes your User model has a 'role' column/property, e.g., $user->role)
        if (!in_array($request->user()->role, $roles)) {
            return response()->json([
                'error' => 'Forbidden.',
                'message' => 'You do not have the required role authorization.'
            ], 403);
        }

        return $next($request);
    }
}
