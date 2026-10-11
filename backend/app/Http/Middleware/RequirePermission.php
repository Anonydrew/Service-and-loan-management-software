<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side permission gate: `permission:loans.view` or, for "any of",
 * `permission:loans.view|collections.view`. Hidden buttons are a convenience;
 * this is the control.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        foreach (explode('|', $permissions) as $permission) {
            if ($user?->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
            'error_code' => 'forbidden',
            'required' => explode('|', $permissions),
        ], 403);
    }
}
