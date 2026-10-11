<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    use Auditable, BelongsToBranch, BelongsToCompany;

    public const STATUSES = ['pending_disbursement', 'active', 'in_arrears', 'closed', 'written_off', 'cancelled'];

    /** Statuses in which money is still owed and repayments are accepted. */
    public const OPEN_STATUSES = ['active', 'in_arrears'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'principal_minor' => 'integer',
            'fee_minor' => 'integer',
            'total_interest_minor' => 'integer',
            'total_payable_minor' => 'integer',
            'net_disbursed_minor' => 'integer',
            'principal_paid_minor' => 'integer',
            'interest_paid_minor' => 'integer',
            'fees_paid_minor' => 'integer',
            'penalty_paid_minor' => 'integer',
            'penalty_charged_minor' => 'integer',
            'written_off_minor' => 'integer',
            'term' => 'integer',
            'grace_period_days' => 'integer',
            'days_in_arrears' => 'integer',
            'interest_rate' => 'string',
            'penalty_value' => 'string',
            'agreement_signed_at' => 'datetime',
            'disbursement_date' => 'date:Y-m-d',
            'first_due_date' => 'date:Y-m-d',
            'maturity_date' => 'date:Y-m-d',
            'disbursed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Nothing is owed before disbursement or after cancellation. */
    public function hasBalance(): bool
    {
        return ! in_array($this->status, ['pending_disbursement', 'cancelled'], true);
    }

    public function outstandingPrincipal(): int
    {
        if (! $this->hasBalance()) {
            return 0;
        }

        return $this->principal_minor - $this->principal_paid_minor - $this->written_off_minor;
    }

    /** Everything owed right now across all installments (principal, interest, fees, penalty). */
    public function outstandingBalance(): int
    {
        if (! $this->hasBalance() || $this->status === 'written_off') {
            return 0;
        }

        $scheduled = $this->total_payable_minor + $this->penalty_charged_minor;
        $paid = $this->principal_paid_minor + $this->interest_paid_minor + $this->fees_paid_minor + $this->penalty_paid_minor;

        return max(0, $scheduled - $paid - $this->written_off_minor);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('installment_number');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function collectionActivities(): HasMany
    {
        return $this->hasMany(CollectionActivity::class)->latest();
    }

    public function loanOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loan_officer_id');
    }

    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
