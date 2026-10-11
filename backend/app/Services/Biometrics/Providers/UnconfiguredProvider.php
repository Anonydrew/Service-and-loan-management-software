<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Providers;

use App\Models\BiometricVerification;
use App\Services\Biometrics\BiometricProvider;
use App\Services\Biometrics\BiometricProviderException;
use App\Services\Biometrics\VerificationResult;
use App\Services\Biometrics\VerificationSubject;
use Illuminate\Http\Request;

/**
 * The default. Refuses to verify anything, so the system can never report a
 * biometric match it did not actually obtain.
 */
final class UnconfiguredProvider implements BiometricProvider
{
    public function __construct(private readonly string $modality) {}

    public function name(): string
    {
        return 'unconfigured';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function start(BiometricVerification $attempt, VerificationSubject $subject): array
    {
        throw new BiometricProviderException('provider_not_configured',
            "No {$this->modality} verification provider is configured. Use document verification and refer the customer for manual review.");
    }

    public function complete(BiometricVerification $attempt, array $payload): VerificationResult
    {
        throw new BiometricProviderException('provider_not_configured');
    }

    public function parseWebhook(Request $request): ?array
    {
        return null;
    }

    public function purge(BiometricVerification $attempt): void {}
}
