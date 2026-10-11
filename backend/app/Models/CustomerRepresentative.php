<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\IdentityHash;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A natural person who acts for a business customer and is identity-verified. */
class CustomerRepresentative extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'full_name', 'position', 'phone', 'email', 'date_of_birth', 'id_type', 'id_number', 'is_primary', 'is_signatory',
    ];

    protected $hidden = ['id_number', 'id_number_hash'];

    protected $appends = ['id_number_masked'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'id_number' => 'encrypted',
            'is_primary' => 'boolean',
            'is_signatory' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CustomerRepresentative $rep): void {
            if ($rep->isDirty('id_number')) {
                $rep->id_number_hash = IdentityHash::make($rep->id_number);
            }
        });
    }

    public function getIdNumberMaskedAttribute(): ?string
    {
        return IdentityHash::mask($this->id_number);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class, 'representative_id');
    }

    public function biometricVerifications(): HasMany
    {
        return $this->hasMany(BiometricVerification::class, 'representative_id');
    }
}
