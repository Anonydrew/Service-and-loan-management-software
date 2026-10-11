<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ServiceRequest;
use App\Services\ServiceDesk\ServiceRequestService;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceRequestController extends Controller
{
    public function __construct(private readonly ServiceRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        $page = ServiceRequest::query()
            ->with(['customer:id,display_name,customer_number,phone', 'service:id,name,category', 'assignee:id,name', 'branch:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->input('priority')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('request_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('display_name', 'like', $term)));
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($page);
    }

    public function show(ServiceRequest $serviceRequest): JsonResponse
    {
        return response()->json($serviceRequest->load([
            'customer', 'service', 'assignee:id,name', 'branch:id,name', 'createdBy:id,name',
            'invoice:id,invoice_number,status,total_minor,amount_paid_minor', 'updates.user:id,name',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $serviceRequest = $this->requests->create($this->validated($request));

        return response()->json($serviceRequest->load(['customer', 'service']), 201);
    }

    public function update(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        return response()->json($this->requests->update($serviceRequest, $this->validated($request, true))->load(['customer', 'service', 'assignee:id,name']));
    }

    public function transition(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ServiceRequest::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
            'create_invoice' => ['boolean'],
        ]);

        return response()->json($this->requests->transition($serviceRequest, $data['status'], $data['note'] ?? null, $data['create_invoice'] ?? true));
    }

    public function invoice(ServiceRequest $serviceRequest): JsonResponse
    {
        return response()->json($this->requests->invoice($serviceRequest), 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $branch = function (string $attribute, mixed $value, Closure $fail): void {
            if (! Branch::query()->whereKey($value)->exists() || ! Tenant::canAccessBranch((int) $value)) {
                $fail('Choose a branch you have access to.');
            }
        };

        return $this->minor($request->validate([
            'customer_id' => [$updating ? 'prohibited' : 'required', 'integer'],
            'service_id' => [$updating ? 'prohibited' : 'required', 'integer'],
            'branch_id' => [$updating ? 'prohibited' : 'nullable', 'integer', $branch],
            'priority' => ['sometimes', Rule::in(ServiceRequest::PRIORITIES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['sometimes', 'numeric', 'min:0.01', 'max:100000'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'scheduled_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer'],
        ]), ['unit_price' => 'unit_price_minor', 'discount' => 'discount_minor']);
    }
}
