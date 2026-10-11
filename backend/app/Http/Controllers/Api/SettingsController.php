<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Biometrics\ProviderRegistry;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function show(): JsonResponse
    {
        $company = Tenant::company();

        return response()->json([
            'company' => $company->only(['name', 'legal_name', 'registration_number', 'tin', 'email', 'phone', 'address', 'timezone', 'currency', 'country']),
            'settings' => $company->allSettings(),
            'integrations' => [
                'biometrics' => $this->providers->status(),
                'sms' => ['driver' => config('services.sms.driver', 'log')],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company.name' => ['sometimes', 'string', 'max:150'],
            'company.legal_name' => ['nullable', 'string', 'max:190'],
            'company.registration_number' => ['nullable', 'string', 'max:60'],
            'company.tin' => ['nullable', 'string', 'max:30'],
            'company.email' => ['nullable', 'email'],
            'company.phone' => ['nullable', 'string', 'max:30'],
            'company.address' => ['nullable', 'string', 'max:255'],
            'company.timezone' => ['sometimes', 'timezone'],

            'settings.onboarding.individual_requires_id_document' => ['sometimes', 'boolean'],
            'settings.onboarding.require_document_verification' => ['sometimes', 'boolean'],
            'settings.onboarding.document_four_eyes' => ['sometimes', 'boolean'],
            'settings.onboarding.individual_requires_face' => ['sometimes', 'boolean'],
            'settings.onboarding.individual_requires_fingerprint' => ['sometimes', 'boolean'],
            'settings.onboarding.business_requires_registration_document' => ['sometimes', 'boolean'],
            'settings.onboarding.business_requires_representative_id' => ['sometimes', 'boolean'],
            'settings.onboarding.representative_requires_face' => ['sometimes', 'boolean'],
            'settings.onboarding.allow_manual_review' => ['sometimes', 'boolean'],
            'settings.onboarding.max_biometric_attempts_per_day' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'settings.onboarding.biometric_retention_days' => ['sometimes', 'integer', 'min:30', 'max:3650'],
            'settings.onboarding.consent_policy_version' => ['sometimes', 'string', 'max:20'],
            'settings.loans.maker_checker' => ['sometimes', 'boolean'],
            'settings.loans.require_signed_agreement' => ['sometimes', 'boolean'],
            'settings.billing.invoice_due_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        $company = Tenant::company();
        $before = $company->allSettings();

        if (isset($data['company'])) {
            $company->fill($data['company']);
        }
        if (isset($data['settings'])) {
            $company->settings = array_replace_recursive($company->settings ?? [], $data['settings']);
        }
        $company->save();

        AuditLogger::log('settings.updated', $company, 'Company settings updated', [
            'before' => $before, 'after' => $company->allSettings(),
        ]);

        return $this->show();
    }
}
