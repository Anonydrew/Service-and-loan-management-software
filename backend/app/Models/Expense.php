<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use Auditable, BelongsToBranch, BelongsToCompany;

    protected $fillable = [
        'branch_id', 'expense_account_id', 'payment_method', 'amount_minor', 'expense_date', 'payee', 'reference', 'description',
    ];

    protected function casts(): array
    {
        return ['expense_date' => 'date:Y-m-d', 'amount_minor' => 'integer', 'voided_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
