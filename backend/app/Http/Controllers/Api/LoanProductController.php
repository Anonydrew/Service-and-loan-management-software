<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanProduct;
use App\Support\BusinessRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => LoanProduct::query()
                ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(LoanProduct $loanProduct): JsonResponse
    {
        return response()->json($loanProduct);
    }

    public function store(Request $request): JsonResponse
    {
        $product = new LoanProduct($this->validated($request));
        $product->save();

        return response()->json($product, 201);
    }

    public function update(Request $request, LoanProduct $loanProduct): JsonResponse
    {
        // Existing loans keep the terms snapshotted at approval, so editing a
        // product only affects future applications.
        $loanProduct->fill($this->validated($request, $loanProduct))->save();

        return response()->json($loanProduct);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?LoanProduct $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('loan_products', 'code')->where('company_id', \App\Support\Tenancy\Tenant::id())->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer_type' => ['required', Rule::in(['individual', 'business', 'both'])],
            'interest_method' => ['required', Rule::in(LoanProduct::INTEREST_METHODS)],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rate_period' => ['required', Rule::in(LoanProduct::RATE_PERIODS)],
            'repayment_frequency' => ['required', Rule::in(LoanProduct::FREQUENCIES)],
            'min_amount' => ['required', 'numeric', 'min:1'],
            'max_amount' => ['required', 'numeric', 'gte:min_amount'],
            'min_term' => ['required', 'integer', 'min:1', 'max:520'],
            'max_term' => ['required', 'integer', 'gte:min_term', 'max:520'],
            'fee_type' => ['required', Rule::in(['percent', 'fixed'])],
            'fee_value' => ['required', 'numeric', 'min:0'],
            'fee_collection' => ['required', Rule::in(['deducted', 'capitalised'])],
            'penalty_type' => ['required', Rule::in(['percent', 'fixed'])],
            'penalty_value' => ['required', 'numeric', 'min:0'],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_debt_service_ratio' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'min_age' => ['nullable', 'integer', 'min:18', 'max:100'],
            'max_age' => ['nullable', 'integer', 'gte:min_age', 'max:120'],
            'max_active_loans' => ['required', 'integer', 'min:1', 'max:20'],
            'requires_guarantor' => ['boolean'],
            'requires_collateral' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if ($data['fee_type'] === 'percent' && (float) $data['fee_value'] >= 100) {
            throw new BusinessRuleException('A percentage fee must be below 100%.', 'invalid_fee');
        }

        return $this->minor($data, ['min_amount' => 'min_amount_minor', 'max_amount' => 'max_amount_minor']);
    }
}
