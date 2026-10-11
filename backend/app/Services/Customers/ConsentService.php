<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\ConsentRecord;
use App\Models\Customer;
use App\Models\CustomerRepresentative;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Tenant;

/**
 * Records what the customer agreed to, which wording they saw (by policy
 * version and text hash), how it was captured and by whom.
 */
final class ConsentService
{
    /** The consent wording shown to the customer, per type. */
    public const TEXTS = [
        'data_processing' => 'I agree that the company may collect, store and process my personal information and identity documents to open and manage my account, as described in its privacy notice.',
        'biometric_processing' => 'I agree that my fingerprint and/or facial image may be captured and compared against my Ghana Card chip or the National Identity Register (or, for other identity documents, by the company\'s verification provider), solely to verify my identity during onboarding. Raw images and fingerprint templates are not kept in the company\'s customer records. I understand that Ghana Card holders must be verified biometrically under L.I. 2523, so if I decline, the company may be unable to complete my onboarding.',
        'credit_check' => 'I authorise the company to obtain my credit report from a licensed credit bureau to assess loan applications.',
        'marketing' => 'I agree to receive information about products and services by SMS or email. I can opt out at any time.',
    ];

    public function record(
        Customer $customer,
        string $type,
        bool $granted,
        string $method,
        ?CustomerRepresentative $representative = null,
    ): ConsentRecord {
        $policyVersion = (string) Tenant::company()->setting('onboarding.consent_policy_version');

        // A new decision supersedes the previous one for the same subject.
        $customer->consents()
            ->where('consent_type', $type)
            ->where('representative_id', $representative?->id)
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now()]);

        $consent = new ConsentRecord([
            'representative_id' => $representative?->id,
            'consent_type' => $type,
            'granted' => $granted,
            'capture_method' => $method,
        ]);
        $consent->customer()->associate($customer);
        $consent->forceFill([
            'policy_version' => $policyVersion,
            'consent_text_hash' => hash('sha256', self::TEXTS[$type]),
            'subject_name' => $representative?->full_name ?? $customer->display_name,
            'granted_at' => $granted ? now() : null,
            'captured_by' => Tenant::user()?->id,
            'ip_address' => request()?->ip(),
        ])->save();

        AuditLogger::log($granted ? 'consent.granted' : 'consent.declined', $consent,
            ucfirst(str_replace('_', ' ', $type)).($granted ? ' consent granted' : ' consent declined'));

        return $consent;
    }

    public function withdraw(ConsentRecord $consent): ConsentRecord
    {
        $consent->forceFill(['withdrawn_at' => now()])->save();
        AuditLogger::log('consent.withdrawn', $consent, ucfirst(str_replace('_', ' ', $consent->consent_type)).' consent withdrawn');

        return $consent;
    }

    public function active(Customer $customer, string $type, ?int $representativeId = null): ?ConsentRecord
    {
        return $customer->consents()
            ->where('consent_type', $type)
            ->where('representative_id', $representativeId)
            ->where('granted', true)
            ->whereNull('withdrawn_at')
            ->latest('id')
            ->first();
    }
}
