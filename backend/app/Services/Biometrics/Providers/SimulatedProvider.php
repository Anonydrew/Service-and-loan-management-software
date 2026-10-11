<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Providers;

use App\Models\BiometricVerification;
use App\Services\Biometrics\BiometricProvider;
use App\Services\Biometrics\BiometricProviderException;
use App\Services\Biometrics\VerificationResult;
use App\Services\Biometrics\VerificationSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

/**
 * DEVELOPMENT AND TEST ONLY. Performs no biometric processing at all: the
 * operator chooses the outcome so every UI state can be exercised. Refuses to
 * construct in production, and every attempt it touches is flagged
 * is_simulated so it can never be mistaken for a real verification.
 */
final class SimulatedProvider implements BiometricProvider
{
    public const OUTCOMES = ['verified', 'no_match', 'liveness_failed', 'poor_quality', 'card_mismatch', 'manual_review', 'provider_unavailable'];

    public function __construct(private readonly string $modality)
    {
        if (app()->isProduction()) {
            throw new LogicException('The simulated biometric provider cannot be used in production.');
        }
    }

    public function name(): string
    {
        return 'simulated_'.$this->modality;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function start(BiometricVerification $attempt, VerificationSubject $subject): array
    {
        return [
            'mode' => 'simulated',
            'outcomes' => self::OUTCOMES,
            'notice' => 'SIMULATED PROVIDER - no biometric data is processed and no identity is actually verified.',
        ];
    }

    public function complete(BiometricVerification $attempt, array $payload): VerificationResult
    {
        $outcome = (string) ($payload['simulate_outcome'] ?? '');
        $ref = 'SIM-'.Str::upper(Str::random(12));
        $liveness = $this->modality === 'face' ? true : null;

        $meta = ['simulated' => true];

        return match ($outcome) {
            'verified' => VerificationResult::verified(0.97, $liveness, $ref, $meta, 'simulated'),
            'no_match' => VerificationResult::failed('no_match', 0.31, $liveness, $ref, $meta, 'simulated'),
            'liveness_failed' => VerificationResult::failed('liveness_failed', null, false, $ref, $meta, 'simulated'),
            'poor_quality' => VerificationResult::failed('poor_quality', null, null, $ref, $meta, 'simulated'),
            'card_mismatch' => VerificationResult::failed('card_mismatch', null, null, $ref, $meta, 'simulated'),
            'manual_review' => VerificationResult::manualReview(0.72, $liveness, $ref, $meta, 'simulated'),
            'provider_unavailable' => throw new BiometricProviderException('provider_unavailable'),
            default => throw new BiometricProviderException('interrupted', 'Choose a simulated outcome.'),
        };
    }

    public function parseWebhook(Request $request): ?array
    {
        return null;
    }

    public function purge(BiometricVerification $attempt): void {}
}
