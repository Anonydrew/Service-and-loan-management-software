<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricVerification;
use App\Models\Customer;
use App\Services\Biometrics\BiometricService;
use App\Services\Biometrics\ProviderRegistry;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Onboarding identity verification only. Nothing here authorises a payment,
 * disbursement, login or any other transaction.
 */
class BiometricController extends Controller
{
    public function __construct(
        private readonly BiometricService $biometrics,
        private readonly ProviderRegistry $providers,
    ) {}

    public function providers(): JsonResponse
    {
        return response()->json([
            'providers' => $this->providers->status(),
            'policy' => Tenant::company()->setting('onboarding'),
            'failure_reasons' => BiometricVerification::FAILURE_REASONS,
        ]);
    }

    public function index(Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => $customer->biometricVerifications()
                ->with(['operator:id,name', 'representative:id,full_name'])
                ->latest('id')
                ->get(),
        ]);
    }

    public function start(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'modality' => ['required', Rule::in(BiometricVerification::MODALITIES)],
            'representative_id' => ['nullable', 'integer'],
        ]);

        $result = $this->biometrics->start($customer, $data['modality'], $data['representative_id'] ?? null);

        return response()->json([
            'attempt' => $result['attempt']->fresh(),
            'client' => $result['client'],
        ], 201);
    }

    public function complete(Request $request, Customer $customer, BiometricVerification $attempt): JsonResponse
    {
        $payload = $request->validate([
            'simulate_outcome' => ['nullable', 'string', 'max:40'],
            'result' => ['nullable', 'array'],
            'signature' => ['nullable', 'string', 'max:200'],
            'job_id' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['attempt' => $this->biometrics->complete($attempt, $payload)->fresh()]);
    }

    public function clientFailure(Request $request, Customer $customer, BiometricVerification $attempt): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(BiometricService::CLIENT_FAILURES)],
        ]);

        return response()->json(['attempt' => $this->biometrics->reportClientFailure($attempt, $data['reason'])->fresh()]);
    }

    public function cancel(Customer $customer, BiometricVerification $attempt): JsonResponse
    {
        return response()->json(['attempt' => $this->biometrics->cancel($attempt)->fresh()]);
    }

    /**
     * Provider callback. The driver authenticates the request (signature) and
     * maps it to an attempt by provider session id; the attempt's own company
     * is then bound before it is updated.
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        $driver = $this->providers->byName($provider);
        $parsed = $driver?->parseWebhook($request);

        if ($parsed === null) {
            return response()->json(['message' => 'Not accepted.'], 404);
        }

        $attempt = Tenant::withoutScopes(fn () => BiometricVerification::query()
            ->where('provider', $provider)
            ->where('status', BiometricVerification::STATUS_PENDING)
            ->get()
            ->first(fn (BiometricVerification $a) => hash_equals((string) $a->provider_session_id, $parsed['session_id'])));

        if ($attempt === null) {
            return response()->json(['message' => 'Unknown session.'], 404);
        }

        Tenant::runAs($attempt->company, function () use ($attempt, $parsed): void {
            $this->biometrics->apply($attempt, $parsed['result']);
            AuditLogger::log('biometric.webhook_received', $attempt, 'Provider result received');
        });

        return response()->json(['ok' => true]);
    }
}
