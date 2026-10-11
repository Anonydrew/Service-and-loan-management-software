<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\IdentityHash;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Auditable, BelongsToBranch, BelongsToCompany, SoftDeletes;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_BUSINESS = 'business';

    public const STATUSES = ['draft', 'active', 'suspended', 'closed', 'rejected'];

    public const ONBOARDING_STATUSES = ['in_progress', 'manual_review', 'completed', 'rejected'];

    public const ID_TYPES = ['ghana_card', 'passport', 'voters_id', 'drivers_licence', 'ssnit', 'other'];

    public const BUSINESS_TYPES = ['sole_proprietorship', 'partnership', 'limited_company', 'ngo', 'cooperative', 'other'];

    protected $fillable = [
        'branch_id', 'type', 'title', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth',
        'marital_status', 'nationality', 'occupation', 'employer', 'business_name', 'trading_name',
        'registration_number', 'tin', 'business_type', 'industry', 'date_of_incorporation',
        'monthly_income_minor', 'email', 'phone', 'alt_phone', 'address', 'digital_address', 'city', 'region',
        'id_type', 'id_number', 'risk_rating', 'relationship_officer_id', 'notes',
    ];

    protected $hidden = ['id_number', 'id_number_hash'];

    protected $appends = ['id_number_masked'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'date_of_incorporation' => 'date:Y-m-d',
            'id_number' => 'encrypted',
            'monthly_income_minor' => 'integer',
            'onboarded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $customer->display_name = $customer->type === self::TYPE_BUSINESS
                ? (string) $customer->business_name
                : trim(implode(' ', array_filter([$customer->first_name, $customer->middle_name, $customer->last_name])));

            if ($customer->isDirty('id_number')) {
                $customer->id_number_hash = IdentityHash::make($customer->id_number);
            }
        });
    }

    public function isIndividual(): bool
    {
        return $this->type === self::TYPE_INDIVIDUAL;
    }

    public function isBusiness(): bool
    {
        return $this->type === self::TYPE_BUSINESS;
    }

    public function getIdNumberMaskedAttribute(): ?string
    {
        return IdentityHash::mask($this->id_number);
    }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    public function representatives(): HasMany
    {
        return $this->hasMany(CustomerRepresentative::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function biometricVerifications(): HasMany
    {
        return $this->hasMany(BiometricVerification::class);
    }

    /** Alias used by scoped route binding of {attempt}. */
    public function attempts(): HasMany
    {
        return $this->biometricVerifications();
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function loanApplications(): HasMany
    {
        return $this->hasMany(LoanApplication::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function relationshipOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relationship_officer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function onboardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'onboarded_by');
    }
}
