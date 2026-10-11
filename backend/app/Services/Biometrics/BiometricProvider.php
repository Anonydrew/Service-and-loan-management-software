<?php

declare(strict_types=1);

namespace App\Services\Biometrics;

use App\Models\BiometricVerification;
use Illuminate\Http\Request;

/**
 * Integration boundary for an onboarding identity-verification provider.
 *
 * An implementation talks to a specialist provider (facial match + liveness)
 * or to compatible fingerprint hardware through its SDK. It must never return
 * raw images or templates for storage - only an outcome and opaque references.
 */
interface BiometricProvider
{
    /** Stable identifier stored on each attempt, e.g. "fingerprint_bridge". */
    public function name(): string;

    public function isConfigured(): bool;

    /** True for the development simulator; results are labelled accordingly. */
    public function isSimulated(): bool;

    /**
     * Opens a verification session and returns what the browser needs to run
     * the capture (an SDK token, a bridge URL and nonce, ...).
     *
     * @return array<string, mixed>
     *
     * @throws BiometricProviderException
     */
    public function start(BiometricVerification $attempt, VerificationSubject $subject): array;

    /**
     * Turns the client's completion payload (an SDK job id, a signed bridge
     * result, ...) into an authenticated outcome.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BiometricProviderException
     */
    public function complete(BiometricVerification $attempt, array $payload): VerificationResult;

    /**
     * Authenticates and parses an asynchronous provider callback.
     *
     * @return array{session_id: string, result: VerificationResult}|null null when not applicable
     */
    public function parseWebhook(Request $request): ?array;

    /** Asks the provider to delete anything it retains for this attempt (retention expiry). */
    public function purge(BiometricVerification $attempt): void;
}
