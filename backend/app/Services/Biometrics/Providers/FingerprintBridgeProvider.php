<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Providers;

use App\Models\BiometricVerification;
use App\Services\Biometrics\BiometricProvider;
use App\Services\Biometrics\BiometricProviderException;
use App\Services\Biometrics\VerificationResult;
use App\Services\Biometrics\VerificationSubject;
use App\Support\GhanaCard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fingerprint verification through a local capture bridge.
 *
 * Browsers cannot talk to fingerprint scanners. A small agent installed on the
 * onboarding workstation wraps the scanner vendor's SDK (e.g. DERMALOG for the
 * ZF1+), listens on loopback, and returns a result signed with HMAC-SHA256
 * using a shared secret. Two modes (FINGERPRINT_BRIDGE_MODE):
 *
 *  - match_on_card: the customer inserts their Ghana Card into the reader and
 *    the live finger is matched against the template on the card's chip. The
 *    bridge must also return the card number read from the chip, which must
 *    equal the customer's recorded Ghana Card number.
 *  - nia_register: the live finger is matched against the National Identity
 *    Register through the NIA IVSP.
 *
 * This server only accepts a result that carries a valid signature, echoes the
 * attempt id, single-use nonce and requested method, and is fresh. Templates
 * and images stay with the bridge/matcher and never reach here.
 *
 * The bridge itself is hardware-specific and is not part of this repository;
 * its contract is documented in docs/BIOMETRICS.md.
 */
final class FingerprintBridgeProvider implements BiometricProvider
{
    public const OUTCOMES = ['match', 'no_match', 'poor_quality', 'device_error', 'card_unreadable'];

    public const MODES = GhanaCard::METHODS;

    public function name(): string
    {
        return 'fingerprint_bridge';
    }

    public function isConfigured(): bool
    {
        return filled(config('biometrics.fingerprint.bridge.secret')) && in_array($this->mode(), self::MODES, true);
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function mode(): string
    {
        return (string) config('biometrics.fingerprint.bridge.mode', 'match_on_card');
    }

    public function start(BiometricVerification $attempt, VerificationSubject $subject): array
    {
        if (! $this->isConfigured()) {
            throw new BiometricProviderException('provider_not_configured');
        }

        if ($this->mode() === 'match_on_card' && ! GhanaCard::applies($subject->idType)) {
            throw new BiometricProviderException('card_required');
        }

        $nonce = Str::random(40);
        $attempt->forceFill(['client_nonce' => $nonce])->save();

        return [
            'mode' => 'bridge',
            'method' => $this->mode(),
            'require_card' => $this->mode() === 'match_on_card',
            'bridge_url' => config('biometrics.fingerprint.bridge.url'),
            'attempt_id' => $attempt->id,
            'nonce' => $nonce,
            // Register mode needs the PIN to query the NIA; match-on-card does
            // not, the bridge reads it from the chip and returns it for checking.
            'subject' => $this->mode() === 'nia_register'
                ? ['id_type' => $subject->idType, 'id_number' => $subject->idNumber, 'country' => $subject->country]
                : ['id_type' => $subject->idType],
            'expires_at' => now()->addMinutes((int) config('biometrics.session_ttl_minutes'))->toIso8601String(),
        ];
    }

    public function complete(BiometricVerification $attempt, array $payload): VerificationResult
    {
        $result = $payload['result'] ?? null;
        $signature = (string) ($payload['signature'] ?? '');
        $mode = $this->mode();

        if (! is_array($result) || $signature === '') {
            return VerificationResult::failed('invalid_signature', metadata: ['reason' => 'missing_result_or_signature']);
        }

        if (! hash_equals(self::sign($result, (string) config('biometrics.fingerprint.bridge.secret')), $signature)) {
            return VerificationResult::failed('invalid_signature', metadata: ['reason' => 'bad_signature']);
        }

        if ((int) ($result['attempt_id'] ?? 0) !== (int) $attempt->id
            || $attempt->client_nonce === null
            || ! hash_equals((string) $attempt->client_nonce, (string) ($result['nonce'] ?? ''))) {
            return VerificationResult::failed('invalid_signature', metadata: ['reason' => 'attempt_or_nonce_mismatch']);
        }

        if (($result['method'] ?? null) !== $mode) {
            return VerificationResult::failed('invalid_signature', metadata: ['reason' => 'method_mismatch']);
        }

        $issuedAt = strtotime((string) ($result['issued_at'] ?? '')) ?: 0;
        if (abs(time() - $issuedAt) > (int) config('biometrics.fingerprint.bridge.max_result_age_seconds')) {
            return VerificationResult::failed('expired', metadata: ['reason' => 'stale_result'], method: $mode);
        }

        $score = isset($result['match_score']) ? (float) $result['match_score'] : null;
        $reference = isset($result['provider_reference']) ? (string) $result['provider_reference'] : null;
        $meta = array_filter([
            'device_model' => $result['device_model'] ?? null,
            'quality_score' => $result['quality_score'] ?? null,
            'finger' => $result['finger'] ?? null,
            'liveness_checked' => $result['liveness_checked'] ?? null,
        ], fn ($v) => $v !== null);

        $outcome = $result['outcome'] ?? null;

        // The card must belong to the person being onboarded, otherwise anyone's
        // card and finger would "verify" this customer.
        if ($mode === 'match_on_card' && $outcome === 'match') {
            $subject = $attempt->representative ?? $attempt->customer;
            if (! GhanaCard::sameNumber((string) ($result['card_pin'] ?? ''), $subject?->id_number)) {
                return VerificationResult::failed('card_mismatch', $score, null, $reference, $meta, $mode);
            }
        }

        $liveness = array_key_exists('liveness_passed', $result) ? (bool) $result['liveness_passed'] : null;
        if ($liveness === false) {
            return VerificationResult::failed('liveness_failed', $score, false, $reference, $meta, $mode);
        }

        return match ($outcome) {
            'match' => $score !== null && $score >= (float) config('biometrics.fingerprint.bridge.min_match_score')
                ? VerificationResult::verified($score, $liveness, $reference, $meta, $mode)
                : VerificationResult::manualReview($score, $liveness, $reference, $meta + ['reason' => 'score_below_threshold'], $mode),
            'no_match' => VerificationResult::failed('no_match', $score, $liveness, $reference, $meta, $mode),
            'poor_quality' => VerificationResult::failed('poor_quality', null, null, $reference, $meta, $mode),
            'card_unreadable' => VerificationResult::failed('card_unreadable', null, null, $reference, $meta, $mode),
            'device_error' => VerificationResult::failed('device_unavailable', null, null, $reference, $meta, $mode),
            default => VerificationResult::failed('invalid_signature', metadata: ['reason' => 'unknown_outcome']),
        };
    }

    /** Canonical signature over the result: keys sorted, compact JSON. */
    public static function sign(array $result, string $secret): string
    {
        ksort($result);

        return hash_hmac('sha256', (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);
    }

    public function parseWebhook(Request $request): ?array
    {
        return null;
    }

    public function purge(BiometricVerification $attempt): void {}
}
