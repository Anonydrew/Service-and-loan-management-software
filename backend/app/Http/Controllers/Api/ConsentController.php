<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\Customer;
use App\Services\Customers\ConsentService;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    public function texts(): JsonResponse
    {
        return response()->json([
            'policy_version' => Tenant::company()->setting('onboarding.consent_policy_version'),
            'texts' => ConsentService::TEXTS,
            'methods' => ConsentRecord::METHODS,
        ]);
    }

    public function index(Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => $customer->consents()->with(['capturedBy:id,name', 'representative:id,full_name'])->latest('id')->get(),
        ]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'consent_type' => ['required', Rule::in(ConsentRecord::TYPES)],
            'granted' => ['required', 'boolean'],
            'capture_method' => ['required', Rule::in(ConsentRecord::METHODS)],
            'representative_id' => ['nullable', 'integer'],
        ]);

        $representative = isset($data['representative_id'])
            ? $customer->representatives()->whereKey($data['representative_id'])->firstOrFail()
            : null;

        $consent = $this->consents->record($customer, $data['consent_type'], $data['granted'], $data['capture_method'], $representative);

        return response()->json($consent, 201);
    }

    public function withdraw(Customer $customer, ConsentRecord $consent): JsonResponse
    {
        return response()->json($this->consents->withdraw($consent));
    }
}
