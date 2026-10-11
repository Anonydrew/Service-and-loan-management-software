<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Customers\OnboardingService;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($this->onboarding->readiness($customer) + [
            'policy' => Tenant::company()->setting('onboarding'),
            'onboarding_status' => $customer->onboarding_status,
            'verification_status' => $customer->verification_status,
        ]);
    }

    public function complete(Customer $customer): JsonResponse
    {
        return response()->json($this->onboarding->complete($customer));
    }

    public function refer(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json($this->onboarding->referForManualReview($customer, $data['reason']));
    }

    public function decide(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json($this->onboarding->decideReview($customer, $data['decision'] === 'approve', $data['notes']));
    }

    public function queue(Request $request): JsonResponse
    {
        return response()->json(
            Customer::query()
                ->where('onboarding_status', 'manual_review')
                ->with(['branch:id,name', 'createdBy:id,name'])
                ->oldest('updated_at')
                ->paginate($this->perPage($request))
        );
    }
}
