<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            User::query()->inCompany()
                ->with(['roles:id,name', 'branches:id,name', 'primaryBranch:id,name'])
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->input('q').'%')
                    ->orWhere('email', 'like', '%'.$request->input('q').'%')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->orderBy('name')
                ->paginate($this->perPage($request))
        );
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(['roles:id,name', 'branches:id,name', 'primaryBranch:id,name']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(10)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = new User([
                'name' => $data['name'], 'email' => strtolower($data['email']), 'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null, 'password' => $data['password'], 'primary_branch_id' => $data['primary_branch_id'],
            ]);
            $user->company_id = Tenant::id();
            $user->status = 'active';
            $user->save();
            $this->syncAccess($user, $data);

            return $user;
        });

        AuditLogger::log('user.created', $user, "Created staff account {$user->email}");

        return response()->json($user->load(['roles:id,name', 'branches:id,name']), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);

        if ($user->id === $request->user()->id && $data['status'] !== 'active') {
            throw new BusinessRuleException('You cannot disable your own account.', 'self_disable');
        }

        DB::transaction(function () use ($user, $data): void {
            $user->fill([
                'name' => $data['name'], 'email' => strtolower($data['email']), 'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null, 'primary_branch_id' => $data['primary_branch_id'],
            ]);
            $user->status = $data['status'];
            $user->save();
            $this->syncAccess($user, $data);

            if ($data['status'] === 'disabled') {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        return response()->json($user->fresh(['roles:id,name', 'branches:id,name']));
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', Password::min(10)->mixedCase()->numbers()]]);

        $user->forceFill(['password' => $data['password'], 'failed_login_attempts' => 0, 'locked_until' => null])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        AuditLogger::log('user.password_reset', $user, "Password reset for {$user->email}");

        return response()->json(['message' => 'Password reset. The user has been signed out everywhere.']);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $companyBranch = Rule::exists('branches', 'id')->where('company_id', Tenant::id());

        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'primary_branch_id' => ['required', 'integer', $companyBranch],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', $companyBranch],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('company_id', Tenant::id())],
        ];
    }

    /** @param array<string, mixed> $data */
    private function syncAccess(User $user, array $data): void
    {
        // Granting a role you do not hold would be privilege escalation.
        $actor = Tenant::user();
        $granted = Role::query()->whereIn('id', $data['role_ids'])->get();
        $actorPermissions = $actor?->permissions() ?? [];
        foreach ($granted as $role) {
            if (array_diff($role->permissions, $actorPermissions) !== []) {
                throw new BusinessRuleException("You cannot assign the {$role->name} role because it grants permissions you do not hold.", 'privilege_escalation');
            }
        }

        $user->roles()->sync($granted->pluck('id'));
        $user->branches()->sync(Branch::query()->whereIn('id', $data['branch_ids'] ?? [])->pluck('id'));
        $user->flushPermissionCache();

        AuditLogger::log('user.access_changed', $user, "Access updated for {$user->email}", [
            'roles' => $granted->pluck('name')->all(),
            'branch_ids' => $data['branch_ids'] ?? [],
            'primary_branch_id' => $data['primary_branch_id'],
        ]);
    }
}
