<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstallment extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $appends = ['total_due_minor', 'total_paid_minor', 'balance_minor'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'installment_number' => 'integer',
            'principal_due_minor' => 'integer',
            'interest_due_minor' => 'integer',
            'fees_due_minor' => 'integer',
            'penalty_due_minor' => 'integer',
            'principal_paid_minor' => 'integer',
            'interest_paid_minor' => 'integer',
            'fees_paid_minor' => 'integer',
            'penalty_paid_minor' => 'integer',
            'penalty_applied_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function getTotalDueMinorAttribute(): int
    {
        return $this->principal_due_minor + $this->interest_due_minor + $this->fees_due_minor + $this->penalty_due_minor;
    }

    public function getTotalPaidMinorAttribute(): int
    {
        return $this->principal_paid_minor + $this->interest_paid_minor + $this->fees_paid_minor + $this->penalty_paid_minor;
    }

    public function getBalanceMinorAttribute(): int
    {
        return $this->total_due_minor - $this->total_paid_minor;
    }

    public function refreshStatus(): void
    {
        if ($this->balance_minor <= 0) {
            $this->status = 'paid';
            $this->paid_at ??= now();
        } else {
            $this->status = $this->total_paid_minor > 0 ? 'partial' : 'pending';
            $this->paid_at = null;
        }
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
