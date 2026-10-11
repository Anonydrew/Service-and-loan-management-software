<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Loan;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\Customers\OnboardingService;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly OnboardingService $onboarding,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->filtered($request)
            ->with(['branch:id,name', 'relationshipOfficer:id,name'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $rules = $this->rules($type, false);
        $data = $this->minor($request->validate($rules), ['monthly_income' => 'monthly_income_minor']);

        $representatives = $data['representatives'] ?? [];
        unset($data['representatives']);

        $customer = $this->customers->create($data, $representatives);

        return response()->json($this->detail($customer), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($this->detail($customer));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $this->minor($request->validate($this->rules($customer->type, true)), ['monthly_income' => 'monthly_income_minor']);
        unset($data['representatives']);

        if (array_key_exists('branch_id', $data) && $customer->loans()->whereIn('status', Loan::OPEN_STATUSES)->exists()
            && (int) $data['branch_id'] !== $customer->branch_id) {
            throw new BusinessRuleException('Customers with open loans cannot be moved to another branch.', 'has_open_loans');
        }

        $this->customers->update($customer, $data);

        return response()->json($this->detail($customer->fresh()));
    }

    public function setStatus(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'closed'])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($customer->onboarding_status !== 'completed') {
            throw new BusinessRuleException('Only onboarded customers can be activated, suspended or closed.', 'onboarding_incomplete');
        }
        if ($data['status'] === 'closed' && $customer->loans()->whereIn('status', Loan::OPEN_STATUSES)->exists()) {
            throw new BusinessRuleException('Customers with open loans cannot be closed.', 'has_open_loans');
        }

        $from = $customer->status;
        $customer->forceFill(['status' => $data['status']])->save();
        AuditLogger::log('customer.status_changed', $customer, "Status {$from} -> {$data['status']}", ['reason' => $data['reason']]);

        return response()->json($this->detail($customer));
    }

    public function activity(Customer $customer): JsonResponse
    {
        $related = [
            'Customer' => [$customer->id],
            'CustomerDocument' => $customer->documents()->withTrashed()->pluck('id')->all(),
            'ConsentRecord' => $customer->consents()->pluck('id')->all(),
            'BiometricVerification' => $customer->biometricVerifications()->pluck('id')->all(),
            'CustomerRepresentative' => $customer->representatives()->pluck('id')->all(),
        ];

        $logs = AuditLog::query()
            ->where('company_id', Tenant::id())
            ->where(function (Builder $q) use ($related): void {
                foreach ($related as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(fn ($w) => $w->where('auditable_type', $type)->whereIn('auditable_id', $ids));
                    }
                }
            })
            ->latest('id')
            ->limit(100)
            ->get(['id', 'action', 'description', 'actor_label', 'auditable_type', 'created_at']);

        return response()->json(['data' => $logs]);
    }

    public function export(Request $request): StreamedResponse
    {
        AuditLogger::log('customers.exported', null, 'Exported customer list', $request->only(['q', 'type', 'status', 'branch_id']));
        $query = $this->filtered($request)->with('branch:id,name')->orderBy('customer_number');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer number', 'Name', 'Type', 'Phone', 'Email', 'Branch', 'Status', 'Onboarding', 'Verification', 'Registered']);
            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $c) {
                    fputcsv($out, [$c->customer_number, $c->display_name, $c->type, $c->phone, $c->email, $c->branch?->name,
                        $c->status, $c->onboarding_status, $c->verification_status, $c->created_at?->toDateString()]);
                }
            });
            fclose($out);
        }, 'customers-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request): Builder
    {
        return Customer::query()
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->input('q')).'%';
                $q->where(fn ($w) => $w->where('display_name', 'like', $term)
                    ->orWhere('customer_number', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('registration_number', 'like', $term));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('onboarding_status'), fn ($q) => $q->where('onboarding_status', $request->input('onboarding_status')))
            ->when($request->filled('verification_status'), fn ($q) => $q->where('verification_status', $request->input('verification_status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')));
    }

    /** @return array<string, mixed> */
    private function detail(Customer $customer): array
    {
        $customer->load(['branch:id,name,code', 'representatives', 'relationshipOfficer:id,name', 'createdBy:id,name', 'reviewer:id,name', 'onboardedBy:id,name']);

        $loans = $customer->loans()->get();
        $openInvoices = Invoice::query()->where('customer_id', $customer->id)->whereIn('status', ['issued', 'partially_paid'])->get();

        return [
            'customer' => $customer,
            'summary' => [
                'loans_total' => $loans->count(),
                'loans_open' => $loans->whereIn('status', Loan::OPEN_STATUSES)->count(),
                'loan_outstanding_minor' => $loans->whereIn('status', Loan::OPEN_STATUSES)->sum(fn (Loan $l) => $l->outstandingBalance()),
                'in_arrears' => $loans->contains(fn ($l) => $l->status === 'in_arrears'),
                'service_requests' => $customer->serviceRequests()->count(),
                'invoice_balance_minor' => $openInvoices->sum(fn (Invoice $i) => $i->balance_minor),
            ],
            'onboarding' => $this->onboarding->readiness($customer),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(?string $type, bool $updating): array
    {
        $req = $updating ? 'sometimes' : 'required';
        $branchRule = function (string $attribute, mixed $value, Closure $fail): void {
            if (! Branch::query()->whereKey($value)->where('is_active', true)->exists() || ! Tenant::canAccessBranch((int) $value)) {
                $fail('Choose an active branch you have access to.');
            }
        };
        $staffRule = function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! User::query()->inCompany()->whereKey($value)->exists()) {
                $fail('Unknown staff member.');
            }
        };

        $rules = [
            'type' => $updating ? ['prohibited'] : ['required', Rule::in([Customer::TYPE_INDIVIDUAL, Customer::TYPE_BUSINESS])],
            'branch_id' => [$req, 'integer', $branchRule],
            'phone' => [$req, 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:255'],
            'digital_address' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'monthly_income' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'risk_rating' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'relationship_officer_id' => ['nullable', 'integer', $staffRule],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($type === Customer::TYPE_BUSINESS) {
            return $rules + [
                'business_name' => [$req, 'string', 'max:190'],
                'trading_name' => ['nullable', 'string', 'max:190'],
                'registration_number' => [$req, 'string', 'max:60'],
                'tin' => ['nullable', 'string', 'max:30'],
                'business_type' => [$req, Rule::in(Customer::BUSINESS_TYPES)],
                'industry' => ['nullable', 'string', 'max:100'],
                'date_of_incorporation' => ['nullable', 'date', 'before_or_equal:today'],
                'representatives' => $updating ? ['prohibited'] : ['required', 'array', 'min:1', 'max:10'],
                'representatives.*.full_name' => ['required', 'string', 'max:190'],
                'representatives.*.position' => ['nullable', 'string', 'max:100'],
                'representatives.*.phone' => ['nullable', 'string', 'max:30'],
                'representatives.*.email' => ['nullable', 'email'],
                'representatives.*.date_of_birth' => ['nullable', 'date', 'before:today'],
                'representatives.*.id_type' => ['nullable', Rule::in(Customer::ID_TYPES)],
                'representatives.*.id_number' => ['nullable', 'string', 'max:40'],
                'representatives.*.is_signatory' => ['boolean'],
            ];
        }

        return $rules + [
            'title' => ['nullable', 'string', 'max:20'],
            'first_name' => [$req, 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [$req, 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => [$req, 'date', 'before:-18 years'],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed', 'other'])],
            'nationality' => ['nullable', 'string', 'size:2'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:190'],
            'id_type' => [$req, Rule::in(Customer::ID_TYPES)],
            'id_number' => [$req, 'string', 'max:40'],
        ];
    }
}
