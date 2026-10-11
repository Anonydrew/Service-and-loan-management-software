<?php

declare(strict_types=1);

namespace App\Services\Biometrics;

use App\Models\BiometricVerification;
use App\Models\Customer;
use App\Models\CustomerRepresentative;
use App\Services\Customers\ConsentService;
use App\Services\Customers\OnboardingService;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Runs onboarding biometric verification attempts.
 *
 * Preconditions enforced here, not in the UI: the subject is a natural person
 * (an individual customer or a business's representative), biometric consent
 * is on record, onboarding is still open, a provider is configured, and the
 * daily attempt limit has not been reached.
 */
final class BiometricService
{
    /** Failures the browser itself can detect before any capture reaches a provider. */
    public const CLIENT_FAILURES = ['camera_permission_denied', 'device_unavailable', 'interrupted', 'poor_quality'];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ConsentService $consents,
        private readonly OnboardingService $onboarding,
    ) {}

    /** @return array{attempt: BiometricVerification, client: array<string, mixed>} */
    public function start(Customer $customer, string $modality, ?int $representativeId = null): array
    {
        if (in_array($customer->onboarding_status, ['completed', 'rejected'], true)) {
            throw new BusinessRuleException('Biometric verification is only available during onboarding.', 'onboarding_closed');
        }

        $representative = $this->resolveSubject($customer, $representativeId);
        $consent = $this->consents->active($customer, 'biometric_processing', $representative?->id);

        if ($consent === null) {
            throw new BusinessRuleException(
                'Biometric consent has not been recorded for this person. Record consent, or refer the customer for manual review.',
                'biometric_consent_required',
            );
        }

        $provider = $this->providers->for($modality);
        if (! $provider->isConfigured()) {
            AuditLogger::log('biometric.unavailable', $customer, "No {$modality} provider configured", ['modality' => $modality]);
            throw new BusinessRuleException(
                BiometricVerification::FAILURE_REASONS['provider_not_configured'].' Verify the identity document and refer for manual review instead.',
                'provider_not_configured',
            );
        }

        $limit = (int) Tenant::company()->setting('onboarding.max_biometric_attempts_per_day');
        $today = $customer->biometricVerifications()
            ->where('modality', $modality)
            ->where('representative_id', $representative?->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($today >= $limit) {
            throw new BusinessRuleException(
                "The daily limit of {$limit} {$modality} verification attempts has been reached. Try again tomorrow or refer for manual review.",
                'attempt_limit_reached',
            );
        }

        return DB::transaction(function () use ($customer, $modality, $representative, $consent, $provider): array {
            // Only one open session per person and method.
            $customer->biometricVerifications()
                ->where('modality', $modality)
                ->where('representative_id', $representative?->id)
                ->where('status', BiometricVerification::STATUS_PENDING)
                ->get()
                ->each(fn (BiometricVerification $open) => $this->close($open, BiometricVerification::STATUS_CANCELLED, 'interrupted'));

            $attempt = new BiometricVerification;
            $attempt->forceFill([
                'customer_id' => $customer->id,
                'representative_id' => $representative?->id,
                'consent_record_id' => $consent->id,
                'modality' => $modality,
                'provider' => $provider->name(),
                'is_simulated' => $provider->isSimulated(),
                'status' => BiometricVerification::STATUS_PENDING,
                'operator_id' => Tenant::user()?->id,
                'started_at' => now(),
                'retain_until' => now()->addDays((int) Tenant::company()->setting('onboarding.biometric_retention_days'))->toDateString(),
            ])->save();

            $subject = $representative ? VerificationSubject::fromRepresentative($representative) : VerificationSubject::fromCustomer($customer);

            try {
                $client = $provider->start($attempt, $subject);
            } catch (BiometricProviderException $e) {
                $this->close($attempt, BiometricVerification::STATUS_FAILED, $e->reason, $e->getMessage());
                $this->syncSubject($customer, $representative, $attempt);

                return ['attempt' => $attempt, 'client' => ['mode' => 'error']];
            }

            if (isset($client['session_id'])) {
                $attempt->forceFill(['provider_session_id' => (string) $client['session_id']])->save();
            }

            $this->syncSubject($customer, $representative, $attempt);
            AuditLogger::log('biometric.started', $attempt, ucfirst($modality).' verification started'.($attempt->is_simulated ? ' (SIMULATED)' : ''), [
                'modality' => $modality, 'provider' => $provider->name(),
            ]);

            return ['attempt' => $attempt, 'client' => $client];
        });
    }

    /** @param array<string, mixed> $payload */
    public function complete(BiometricVerification $attempt, array $payload): BiometricVerification
    {
        $this->assertPending($attempt);

        if ($this->isExpired($attempt)) {
            $this->close($attempt, BiometricVerification::STATUS_FAILED, 'expired');
            $this->syncSubject($attempt->customer, $attempt->representative, $attempt);

            return $attempt;
        }

        $provider = $this->providers->byName($attempt->provider);
        if ($provider === null) {
            $this->close($attempt, BiometricVerification::STATUS_FAILED, 'provider_unavailable', 'The provider used for this attempt is no longer configured.');
            $this->syncSubject($attempt->customer, $attempt->representative, $attempt);

            return $attempt;
        }

        try {
            $result = $provider->complete($attempt, $payload);
        } catch (BiometricProviderException $e) {
            $this->close($attempt, BiometricVerification::STATUS_FAILED, $e->reason, $e->getMessage());
            $this->syncSubject($attempt->customer, $attempt->representative, $attempt);

            return $attempt;
        }

        return $this->apply($attempt, $result);
    }

    public function apply(BiometricVerification $attempt, VerificationResult $result): BiometricVerification
    {
        $this->assertPending($attempt);

        DB::transaction(function () use ($attempt, $result): void {
            $attempt->forceFill([
                'status' => $result->status,
                'failure_reason' => $result->failureReason,
                'failure_message' => $result->failureReason ? BiometricVerification::FAILURE_REASONS[$result->failureReason] : null,
                'match_score' => $result->matchScore,
                'liveness_passed' => $result->livenessPassed,
                'provider_reference' => $result->providerReference,
                'metadata' => $result->metadata ?: null,
                'verification_method' => $result->method,
                'client_nonce' => null,
                'completed_at' => now(),
            ])->save();

            $this->syncSubject($attempt->customer, $attempt->representative, $attempt);

            AuditLogger::log('biometric.'.$result->status, $attempt, sprintf(
                '%s verification %s%s%s', ucfirst($attempt->modality), str_replace('_', ' ', $result->status),
                $result->failureReason ? ' ('.str_replace('_', ' ', $result->failureReason).')' : '',
                $attempt->is_simulated ? ' [SIMULATED]' : '',
            ), ['match_score' => $result->matchScore, 'liveness' => $result->livenessPassed]);

            if ($result->failureReason === 'invalid_signature') {
                AuditLogger::log('security.biometric_signature_rejected', $attempt, 'A biometric result failed authentication and was rejected');
            }
        });

        return $attempt;
    }

    /** A failure detected in the browser (camera blocked, no device, closed tab, poor capture). */
    public function reportClientFailure(BiometricVerification $attempt, string $reason): BiometricVerification
    {
        $this->assertPending($attempt);

        $status = $reason === 'interrupted' ? BiometricVerification::STATUS_CANCELLED : BiometricVerification::STATUS_FAILED;
        $this->close($attempt, $status, $reason);
        $this->syncSubject($attempt->customer, $attempt->representative, $attempt);
        AuditLogger::log('biometric.'.$status, $attempt, ucfirst($attempt->modality).' verification '.$status.' ('.str_replace('_', ' ', $reason).')');

        return $attempt;
    }

    public function cancel(BiometricVerification $attempt): BiometricVerification
    {
        return $this->reportClientFailure($attempt, 'interrupted');
    }

    /** Clears provider references once the retention period has passed. */
    public function purge(BiometricVerification $attempt): void
    {
        $this->providers->byName($attempt->provider)?->purge($attempt);

        $attempt->forceFill([
            'provider_reference' => null,
            'provider_session_id' => null,
            'metadata' => null,
            'client_nonce' => null,
            'purged_at' => now(),
        ])->save();
    }

    private function resolveSubject(Customer $customer, ?int $representativeId): ?CustomerRepresentative
    {
        if ($customer->isIndividual()) {
            if ($representativeId !== null) {
                throw new BusinessRuleException('Individual customers are verified as themselves.', 'invalid_subject');
            }

            return null;
        }

        // A company has no face or fingerprint: verify a named representative.
        if ($representativeId === null) {
            throw new BusinessRuleException('Choose the authorised representative to verify. A business itself cannot be biometrically verified.', 'representative_required');
        }

        return $customer->representatives()->whereKey($representativeId)->firstOrFail();
    }

    private function close(BiometricVerification $attempt, string $status, string $reason, ?string $message = null): void
    {
        $attempt->forceFill([
            'status' => $status,
            'failure_reason' => $reason,
            'failure_message' => $message ?? (BiometricVerification::FAILURE_REASONS[$reason] ?? null),
            'client_nonce' => null,
            'completed_at' => now(),
        ])->save();
    }

    private function syncSubject(Customer $customer, ?CustomerRepresentative $rep, BiometricVerification $attempt): void
    {
        $rep?->forceFill(['verification_status' => $attempt->status])->save();
        $this->onboarding->refreshVerificationStatus($customer);
    }

    private function assertPending(BiometricVerification $attempt): void
    {
        if (! $attempt->isOpen()) {
            throw new BusinessRuleException('This verification attempt is no longer open. Start a new attempt.', 'attempt_closed');
        }
    }

    private function isExpired(BiometricVerification $attempt): bool
    {
        return $attempt->started_at !== null
            && $attempt->started_at->addMinutes((int) config('biometrics.session_ttl_minutes'))->isPast();
    }
}
