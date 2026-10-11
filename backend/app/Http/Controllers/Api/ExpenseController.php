<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\LoanRepayment;
use App\Services\Accounting\ExpenseService;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Expense::query()
                ->with(['account:id,code,name', 'branch:id,name', 'recordedBy:id,name'])
                ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
                ->when($request->filled('account_id'), fn ($q) => $q->where('expense_account_id', $request->integer('account_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->input('to')))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', '%'.$request->input('q').'%')
                    ->orWhere('payee', 'like', '%'.$request->input('q').'%')->orWhere('expense_number', 'like', '%'.$request->input('q').'%')))
                ->latest('expense_date')->latest('id')
                ->paginate($this->perPage($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $branch = function (string $attribute, mixed $value, Closure $fail): void {
            if (! Branch::query()->whereKey($value)->exists() || ! Tenant::canAccessBranch((int) $value)) {
                $fail('Choose a branch you have access to.');
            }
        };

        $data = $this->minor($request->validate([
            'branch_id' => ['required', 'integer', $branch],
            'expense_account_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::in(LoanRepayment::METHODS)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'payee' => ['nullable', 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
        ]), ['amount' => 'amount_minor']);

        return response()->json($this->expenses->record($data)->load('account:id,code,name'), 201);
    }

    public function void(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->expenses->void($expense, $data['reason']));
    }
}
