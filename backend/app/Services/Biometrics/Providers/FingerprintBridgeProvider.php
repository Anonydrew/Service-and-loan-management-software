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

/**
 * Fingerprint verification through a local capture bridge.
 *
 * Browsers cannot talk to fingerprint scanners. A small agent installed on the
 * onboarding workstation wraps the scanner vendor's SDK (and the matching
 * service it uses), listens on loopback, and returns a result signed with
 * HMAC-SHA256 using a shared secret. This server only accepts a result that:
 *  - carries a valid signature,
 *  - echoes the attempt id and single-use nonce issued by start(), and
 *  - was issued within the allowed age.
 * Templates and images stay with the bridge/matcher and never reach here.
 *
 * The bridge itself is hardware-specific and is not part of this repository;
 * its contract is documented in docs/BIOMETRICS.md.
 */
final class FingerprintBridgeProvider implements BiometricProvider
{
    public const OUTCOMES = ['match', 'no_match', 'poor_quality', 'device_error'];

    public function name(): string
    {
        return 'fingerprint_bridge';
    }

    public function isConfigured(): bool
    {
        return filled(config('biometrics.fingerprint.bridge.secret'));
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function start(BiometricVerification $attempt, VerificationSubject $subject): array
    {
        if (! $this->isConfigured()) {
            throw new BiometricProviderException('provider_not_configured');
        }

        $nonce = Str::random(40);
        $attempt->forceFill(['client_nonce' => $nonce])->save();

        return [
            'mode' => 'bridge',
            'bridge_url' => config('biometrics.fingerprint.bridge.url'),
            'attempt_id' => $attempt->id,
            'nonce' => $nonce,
            // The bridge passes this to its matcher; it is not stored by the bridge.
            'subject' => ['id_type' => $subject->idType, 'id_number' => $subject->idNumber, 'country' => $subject->country],
            'expires_at' => now()->addMinutes((int) config('biometrics.session_ttl_minutes'))->toIso8601String(),
        ];
    }

    public function complete(BiometricVerification $attempt, array $payload): VerificationResult
    {
        $result = $payload['result'] ?? null;
        $signature = (string) ($payload['signature'] ?? '');

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

        $issuedAt = strtotime((string) ($result['issued_at'] ?? '')) ?: 0;
        if (abs(time() - $issuedAt) > (int) config('biometrics.fingerprint.bridge.max_result_age_seconds')) {
            return VerificationResult::failed('expired', metadata: ['reason' => 'stale_result']);
        }

        $score = isset($result['match_score']) ? (float) $result['match_score'] : null;
        $reference = isset($result['provider_reference']) ? (string) $result['provider_reference'] : null;
        $meta = array_filter([
            'device_model' => $result['device_model'] ?? null,
            'quality_score' => $result['quality_score'] ?? null,
            'finger' => $result['finger'] ?? null,
        ], fn ($v) => $v !== null);

        return match ($result['outcome'] ?? null) {
            'match' => $score !== null && $score >= (float) config('biometrics.fingerprint.bridge.min_match_score')
                ? VerificationResult::verified($score, null, $reference, $meta)
                : VerificationResult::manualReview($score, null, $reference, $meta + ['reason' => 'score_below_threshold']),
            'no_match' => VerificationResult::failed('no_match', $score, null, $reference, $meta),
            'poor_quality' => VerificationResult::failed('poor_quality', null, null, $reference, $meta),
            'device_error' => VerificationResult::failed('device_unavailable', null, null, $reference, $meta),
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
