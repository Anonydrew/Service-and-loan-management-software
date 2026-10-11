<?php

declare(strict_types=1);

namespace App\Services\Biometrics;

use App\Models\BiometricVerification;
use InvalidArgumentException;

final class VerificationResult
{
    /** @param array<string, mixed> $metadata non-biometric diagnostics only */
    public function __construct(
        public readonly string $status,
        public readonly ?string $failureReason = null,
        public readonly ?float $matchScore = null,
        public readonly ?bool $livenessPassed = null,
        public readonly ?string $providerReference = null,
        public readonly array $metadata = [],
        /** How identity was confirmed: match_on_card, nia_register, provider-specific, or simulated. */
        public readonly ?string $method = null,
    ) {
        if (! in_array($status, [BiometricVerification::STATUS_VERIFIED, BiometricVerification::STATUS_FAILED, BiometricVerification::STATUS_MANUAL_REVIEW], true)) {
            throw new InvalidArgumentException("Invalid verification outcome [{$status}].");
        }

        if ($failureReason !== null && ! array_key_exists($failureReason, BiometricVerification::FAILURE_REASONS)) {
            throw new InvalidArgumentException("Unknown failure reason [{$failureReason}].");
        }
    }

    public static function verified(float $score, ?bool $liveness, ?string $reference, array $metadata = [], ?string $method = null): self
    {
        return new self(BiometricVerification::STATUS_VERIFIED, null, $score, $liveness, $reference, $metadata, $method);
    }

    public static function failed(string $reason, ?float $score = null, ?bool $liveness = null, ?string $reference = null, array $metadata = [], ?string $method = null): self
    {
        return new self(BiometricVerification::STATUS_FAILED, $reason, $score, $liveness, $reference, $metadata, $method);
    }

    public static function manualReview(?float $score, ?bool $liveness, ?string $reference, array $metadata = [], ?string $method = null): self
    {
        return new self(BiometricVerification::STATUS_MANUAL_REVIEW, null, $score, $liveness, $reference, $metadata, $method);
    }
}
