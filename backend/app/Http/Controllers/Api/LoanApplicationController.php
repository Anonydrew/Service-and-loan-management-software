<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\LoanCalculator;
use App\Services\Loans\LoanService;
use App\Support\Money;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanApplicationController extends Controller
{
    public function __construct(
        private readonly LoanService $loans,
        private readonly LoanCalculator $calculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = LoanApplication::query()
            ->with(['customer:id,display_name,customer_number,type', 'product:id,name', 'branch:id,name', 'createdBy:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('application_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('display_name', 'like', $term)));
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($page);
    }

    public function show(LoanApplication $application): JsonResponse
    {
        return response()->json($application->load([
            'customer', 'product', 'branch:id,name', 'loan:id,loan_application_id,loan_number,status',
            'createdBy:id,name', 'decidedBy:id,name', 'loanOfficer:id,name',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $application = $this->loans->createApplication($this->validated($request));

        return response()->json($application->load(['customer', 'product']), 201);
    }

    public function update(Request $request, LoanApplication $application): JsonResponse
    {
        return response()->json($this->loans->updateApplication($application, $this->validated($request, true))->load(['customer', 'product']));
    }

    public function submit(LoanApplication $application): JsonResponse
    {
        return response()->json($this->loans->submit($application));
    }

    public function approve(Request $request, LoanApplication $application): JsonResponse
    {
        $data = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:1'],
            'approved_term' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $loan = $this->loans->approve(
            $application,
            isset($data['approved_amount']) ? Money::toMinor($data['approved_amount']) : null,
            $data['approved_term'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['application' => $application->fresh(), 'loan' => $loan], 201);
    }

    public function reject(Request $request, LoanApplication $application): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);

        return response()->json($this->loans->reject($application, $data['notes']));
    }

    public function cancel(Request $request, LoanApplication $application): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->loans->cancel($application, $data['reason']));
    }

    /** Schedule preview for the application form and the product calculator. */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'loan_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'term' => ['required', 'integer', 'min:1', 'max:520'],
            'start_date' => ['nullable', 'date'],
        ]);

        $product = LoanProduct::query()->findOrFail($data['loan_product_id']);
        $principal = Money::toMinor($data['amount']);
        $fee = $this->calculator->fee($principal, $product->fee_type, (string) $product->fee_value);

        $schedule = $this->calculator->schedule(
            $principal, (string) $product->interest_rate, $product->rate_period, $product->interest_method,
            (int) $data['term'], $product->repayment_frequency, now()->parse($data['start_date'] ?? now()),
            $product->fee_collection === 'capitalised' ? $fee : 0,
        );

        return response()->json($schedule + [
            'fee_minor' => $fee,
            'fee_collection' => $product->fee_collection,
            'net_disbursed_minor' => $product->fee_collection === 'deducted' ? $principal - $fee : $principal,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';
        $staff = function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! User::query()->inCompany()->whereKey($value)->exists()) {
                $fail('Unknown staff member.');
            }
        };

        $data = $request->validate([
            'customer_id' => [$updating ? 'prohibited' : 'required', 'integer'],
            'loan_product_id' => [$req, 'integer'],
            'amount' => [$req, 'numeric', 'min:1'],
            'term' => [$req, 'integer', 'min:1', 'max:520'],
            'purpose' => [$req, 'string', 'max:255'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'monthly_obligations' => ['nullable', 'numeric', 'min:0'],
            'loan_officer_id' => ['nullable', 'integer', $staff],
            'guarantors' => ['nullable', 'array', 'max:5'],
            'guarantors.*.name' => ['required', 'string', 'max:190'],
            'guarantors.*.phone' => ['required', 'string', 'max:30'],
            'guarantors.*.relationship' => ['nullable', 'string', 'max:60'],
            'guarantors.*.id_number' => ['nullable', 'string', 'max:40'],
            'collateral' => ['nullable', 'array', 'max:10'],
            'collateral.*.description' => ['required', 'string', 'max:255'],
            'collateral.*.estimated_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['loan_product_id'])) {
            LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);
        }

        $data = $this->minor($data, [
            'amount' => 'amount_requested_minor',
            'monthly_income' => 'monthly_income_minor',
            'monthly_obligations' => 'monthly_obligations_minor',
        ]);
        if (array_key_exists('monthly_obligations_minor', $data) && $data['monthly_obligations_minor'] === null) {
            $data['monthly_obligations_minor'] = 0;
        }

        return $data;
    }
}
