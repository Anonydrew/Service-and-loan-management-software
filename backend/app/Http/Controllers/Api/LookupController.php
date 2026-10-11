<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CollectionActivity;
use App\Models\ConsentRecord;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\Service;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;

/** Reference data for forms and filters, in one request. */
class LookupController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $branchIds = Tenant::branchIds();

        return response()->json([
            'branches' => Branch::query()->where('is_active', true)
                ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
                ->orderBy('name')->get(['id', 'name', 'code']),
            'staff' => User::query()->inCompany()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'job_title']),
            'loan_products' => LoanProduct::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'code', 'customer_type', 'interest_method', 'interest_rate', 'rate_period', 'repayment_frequency',
                    'min_amount_minor', 'max_amount_minor', 'min_term', 'max_term', 'requires_guarantor', 'requires_collateral']),
            'services' => Service::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'code', 'category', 'price_minor', 'price_is_variable', 'tax_rate', 'requires_appointment']),
            'enums' => [
                'id_types' => Customer::ID_TYPES,
                'business_types' => Customer::BUSINESS_TYPES,
                'document_types' => CustomerDocument::TYPES,
                'identity_document_types' => CustomerDocument::IDENTITY_TYPES,
                'business_document_types' => CustomerDocument::BUSINESS_TYPES,
                'payment_methods' => LoanRepayment::METHODS,
                'consent_types' => ConsentRecord::TYPES,
                'consent_methods' => ConsentRecord::METHODS,
                'collection_activity_types' => CollectionActivity::TYPES,
            ],
        ]);
    }
}
