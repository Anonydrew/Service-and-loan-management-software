<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_FAILURES = 5;

    private const LOCK_MINUTES = 15;

    /**
     * Browser clients get a session cookie (Sanctum SPA auth). Requests
     * without a session (API clients, integrations) get a bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();

        if ($user?->locked_until?->isFuture()) {
            throw ValidationException::withMessages([
                'email' => 'Too many failed attempts. Try again after '.$user->locked_until->format('H:i').'.',
            ]);
        }

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            if ($user !== null) {
                $failures = $user->failed_login_attempts + 1;
                $user->forceFill([
                    'failed_login_attempts' => $failures,
                    'locked_until' => $failures >= self::MAX_FAILURES ? now()->addMinutes(self::LOCK_MINUTES) : null,
                ])->saveQuietly();
                AuditLogger::log('auth.login_failed', $user, 'Failed sign-in', ['ip' => $request->ip()], companyId: $user->company_id);
            }

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->isActive() || $user->company?->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'This account is disabled. Contact your administrator.']);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        AuditLogger::log('auth.login', $user, 'Signed in', companyId: $user->company_id);

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return response()->json(['user' => $this->profile($user)]);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'api', ['*'], now()->addHours(12));

        return response()->json(['user' => $this->profile($user), 'token' => $token->plainTextToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::log('auth.logout', $request->user(), 'Signed out');

        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();
        AuditLogger::log('auth.password_changed', $request->user(), 'Changed own password');

        return response()->json(['message' => 'Password updated.']);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        return Tenant::runAs($user->company, function () use ($user): array {
            $branchIds = $user->accessibleBranchIds();
            $company = $user->company;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'job_title' => $user->job_title,
                'roles' => $user->roles()->pluck('name'),
                'permissions' => $user->permissions(),
                'primary_branch_id' => $user->primary_branch_id,
                'all_branches' => $branchIds === null,
                'branches' => Branch::query()
                    ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']),
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'currency' => $company->currency,
                    'timezone' => $company->timezone,
                ],
            ];
        }, $user);
    }
}
