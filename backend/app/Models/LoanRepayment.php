<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanRepayment extends Model
{
    use Auditable, BelongsToBranch, BelongsToCompany;

    public const METHODS = ['cash', 'mobile_money', 'bank_transfer', 'cheque', 'card'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'principal_minor' => 'integer',
            'interest_minor' => 'integer',
            'fees_minor' => 'integer',
            'penalty_minor' => 'integer',
            'paid_on' => 'date:Y-m-d',
            'reversed_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RepaymentAllocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
