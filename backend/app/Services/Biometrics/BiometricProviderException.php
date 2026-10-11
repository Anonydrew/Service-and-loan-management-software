<?php

declare(strict_types=1);

namespace App\Services\Biometrics;

use App\Models\BiometricVerification;
use RuntimeException;

/** A provider could not run or complete a verification; carries the failure reason code. */
final class BiometricProviderException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? (BiometricVerification::FAILURE_REASONS[$reason] ?? $reason));
    }
}
