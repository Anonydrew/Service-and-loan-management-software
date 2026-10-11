<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\IdentityHash;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerDocument extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    /** Identity documents that can satisfy the "proof of identity" requirement. */
    public const IDENTITY_TYPES = ['ghana_card', 'passport', 'voters_id', 'drivers_licence'];

    /** Documents that can satisfy the "business registration" requirement. */
    public const BUSINESS_TYPES = ['business_registration', 'certificate_of_incorporation', 'certificate_to_commence', 'tin_certificate'];

    public const TYPES = [
        'ghana_card', 'passport', 'voters_id', 'drivers_licence', 'ssnit',
        'business_registration', 'certificate_of_incorporation', 'certificate_to_commence', 'tin_certificate',
        'board_resolution', 'proof_of_address', 'bank_statement', 'payslip', 'signed_consent_form',
        'loan_agreement', 'service_agreement', 'other',
    ];

    protected $fillable = ['representative_id', 'document_type', 'document_number', 'issue_date', 'expiry_date'];

    protected $hidden = ['document_number', 'document_number_hash', 'file_path', 'checksum'];

    protected $appends = ['document_number_masked'];

    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'issue_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CustomerDocument $doc): void {
            if ($doc->isDirty('document_number')) {
                $doc->document_number_hash = IdentityHash::make($doc->document_number);
            }
        });
    }

    public function getDocumentNumberMaskedAttribute(): ?string
    {
        return IdentityHash::mask($this->document_number);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(CustomerRepresentative::class, 'representative_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
