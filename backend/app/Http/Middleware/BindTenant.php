<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's company (and branch access) for the rest of
 * the request. Runs after auth:sanctum on every protected route.
 */
final class BindTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.', 'error_code' => 'unauthenticated'], 401);
        }

        // Always read the company and permissions fresh, so a settings change,
        // suspension or role edit takes effect on the very next request.
        $company = Company::query()->find($user->company_id);
        $user->setRelation('company', $company);
        $user->flushPermissionCache();

        if (! $user->isActive() || $company === null || $company->status !== 'active') {
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            return response()->json(['message' => 'This account is disabled.', 'error_code' => 'account_disabled'], 401);
        }

        Tenant::set($company, $user);

        try {
            return $next($request);
        } finally {
            Tenant::forget();
        }
    }
}
