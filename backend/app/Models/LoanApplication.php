<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoanApplication extends Model
{
    use Auditable, BelongsToBranch, BelongsToCompany;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'disbursed'];

    protected $fillable = [
        'customer_id', 'loan_product_id', 'amount_requested_minor', 'term', 'purpose', 'monthly_income_minor',
        'monthly_obligations_minor', 'guarantors', 'collateral', 'loan_officer_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested_minor' => 'integer',
            'approved_amount_minor' => 'integer',
            'monthly_income_minor' => 'integer',
            'monthly_obligations_minor' => 'integer',
            'term' => 'integer',
            'approved_term' => 'integer',
            'guarantors' => 'array',
            'collateral' => 'array',
            'eligibility' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function loan(): HasOne
    {
        return $this->hasOne(Loan::class);
    }

    public function loanOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loan_officer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
