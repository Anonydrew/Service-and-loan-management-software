<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRecord extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = ['data_processing', 'biometric_processing', 'credit_check', 'marketing'];

    public const METHODS = ['signed_form', 'electronic', 'verbal_recorded'];

    protected $fillable = ['representative_id', 'consent_type', 'granted', 'capture_method'];

    protected function casts(): array
    {
        return ['granted' => 'boolean', 'granted_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->granted && $this->withdrawn_at === null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(CustomerRepresentative::class, 'representative_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
