<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Company extends Model
{
    use Auditable;

    /** Settings a company starts with; stored overrides are merged over these. */
    public const DEFAULT_SETTINGS = [
        'onboarding' => [
            'individual_requires_id_document' => true,
            'require_document_verification' => true,
            'document_four_eyes' => true,
            'individual_requires_face' => false,
            'individual_requires_fingerprint' => false,
            'business_requires_registration_document' => true,
            'business_requires_representative_id' => true,
            'representative_requires_face' => false,
            'allow_manual_review' => true,
            'max_biometric_attempts_per_day' => 3,
            'biometric_retention_days' => 365,
            'consent_policy_version' => '2026-10-LI2523',
        ],
        'loans' => [
            'maker_checker' => true,
            'require_signed_agreement' => true,
        ],
        'billing' => [
            'invoice_due_days' => 14,
        ],
    ];

    protected $fillable = [
        'name', 'legal_name', 'registration_number', 'tin', 'email', 'phone', 'address', 'timezone',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->allSettings(), $key, $default);
    }

    /** @return array<string, mixed> */
    public function allSettings(): array
    {
        return array_replace_recursive(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
