<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\BusinessRuleException;
use App\Support\Permissions;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Role::query()->withCount('users')->orderBy('name')->get()]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['modules' => Permissions::MODULES]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $role = new Role($data + ['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4))]);
        $role->save();

        return response()->json($role, 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $this->validated($request, $role);

        if ($role->slug === 'administrator') {
            throw new BusinessRuleException('The Administrator role cannot be edited, so the company can never lock itself out.', 'system_role');
        }

        $role->fill($data)->save();

        return response()->json($role);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system || $role->users()->exists()) {
            throw new BusinessRuleException('System roles and roles assigned to staff cannot be deleted.', 'role_in_use');
        }

        $role->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->where('company_id', Tenant::id())->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        // A role editor cannot create a role more powerful than themselves.
        $missing = array_diff($data['permissions'], $request->user()->permissions());
        if ($missing !== []) {
            throw new BusinessRuleException('You cannot grant permissions you do not hold: '.implode(', ', $missing), 'privilege_escalation');
        }

        $data['permissions'] = array_values(array_unique($data['permissions']));

        return $data;
    }
}
