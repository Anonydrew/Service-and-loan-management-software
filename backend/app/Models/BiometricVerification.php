<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One biometric verification attempt made during onboarding.
 *
 * Holds the outcome and opaque provider identifiers only - never a face image,
 * fingerprint image or template.
 */
class BiometricVerification extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MANUAL_REVIEW = 'manual_review';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = ['not_started', 'pending', 'verified', 'failed', 'manual_review', 'cancelled'];

    public const MODALITIES = ['face', 'fingerprint'];

    /** Machine-readable failure reasons, each with an operator-facing message. */
    public const FAILURE_REASONS = [
        'camera_permission_denied' => 'Camera access was blocked in the browser. Allow camera access and try again.',
        'device_unavailable' => 'No compatible camera or fingerprint device was found on this workstation.',
        'poor_quality' => 'The capture quality was too low. Improve lighting, remove glasses or hats, and try again.',
        'liveness_failed' => 'The liveness check did not pass. The customer must be physically present.',
        'no_match' => 'The capture did not match the identity record.',
        'provider_unavailable' => 'The verification provider could not be reached. Try again later or refer for manual review.',
        'provider_not_configured' => 'No verification provider is configured for this method.',
        'interrupted' => 'The verification session was interrupted before it finished.',
        'expired' => 'The verification session expired before a result was received.',
        'invalid_signature' => 'The result could not be authenticated and was rejected.',
        'card_required' => 'Match-on-card verification needs the person\'s Ghana Card. Use NIA register verification for other identity documents.',
        'card_mismatch' => 'The card in the reader does not belong to this person. Ask for their own Ghana Card.',
        'card_unreadable' => 'The Ghana Card chip could not be read. Clean the chip, re-insert the card and try again.',
    ];

    protected $fillable = [];

    protected $hidden = ['provider_reference', 'provider_session_id', 'metadata', 'client_nonce'];

    protected function casts(): array
    {
        return [
            'is_simulated' => 'boolean',
            'liveness_passed' => 'boolean',
            'match_score' => 'float',
            'provider_reference' => 'encrypted',
            'provider_session_id' => 'encrypted',
            'metadata' => 'encrypted:array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'retain_until' => 'date:Y-m-d',
            'purged_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(CustomerRepresentative::class, 'representative_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class, 'consent_record_id');
    }
}
