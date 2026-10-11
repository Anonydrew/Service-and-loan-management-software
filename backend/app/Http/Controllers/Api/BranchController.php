<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Support\BusinessRuleException;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branchIds = Tenant::branchIds();

        $branches = Branch::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $customers = Customer::query()->selectRaw('branch_id, count(*) as c')->groupBy('branch_id')->pluck('c', 'branch_id');
        $loans = Loan::query()->whereIn('status', Loan::OPEN_STATUSES)->selectRaw('branch_id, count(*) as c')->groupBy('branch_id')->pluck('c', 'branch_id');

        return response()->json(['data' => $branches->map(fn (Branch $b) => $b->toArray() + [
            'customers_count' => (int) ($customers[$b->id] ?? 0),
            'open_loans_count' => (int) ($loans[$b->id] ?? 0),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $branch = new Branch($this->validated($request));
        $branch->save();

        return response()->json($branch, 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $this->validated($request, $branch);

        if (isset($data['is_active']) && ! $data['is_active'] && Loan::query()->where('branch_id', $branch->id)->whereIn('status', Loan::OPEN_STATUSES)->exists()) {
            throw new BusinessRuleException('Branches with open loans cannot be deactivated.', 'has_open_loans');
        }

        $branch->fill($data)->save();

        return response()->json($branch);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('branches', 'code')->where('company_id', Tenant::id())->ignore($branch?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'is_head_office' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
