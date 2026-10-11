<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LoanProduct extends Model
{
    use Auditable, BelongsToCompany;

    public const INTEREST_METHODS = ['flat', 'reducing_balance'];

    public const RATE_PERIODS = ['per_month', 'per_annum'];

    public const FREQUENCIES = ['weekly', 'biweekly', 'monthly'];

    protected $fillable = [
        'name', 'code', 'description', 'customer_type', 'interest_method', 'interest_rate', 'rate_period',
        'repayment_frequency', 'min_amount_minor', 'max_amount_minor', 'min_term', 'max_term', 'fee_type',
        'fee_value', 'fee_collection', 'penalty_type', 'penalty_value', 'grace_period_days',
        'max_debt_service_ratio', 'min_age', 'max_age', 'max_active_loans', 'requires_guarantor',
        'requires_collateral', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'string',
            'fee_value' => 'string',
            'penalty_value' => 'string',
            'max_debt_service_ratio' => 'string',
            'min_amount_minor' => 'integer',
            'max_amount_minor' => 'integer',
            'min_term' => 'integer',
            'max_term' => 'integer',
            'grace_period_days' => 'integer',
            'max_active_loans' => 'integer',
            'requires_guarantor' => 'boolean',
            'requires_collateral' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function allowsCustomerType(string $type): bool
    {
        return $this->customer_type === 'both' || $this->customer_type === $type;
    }
}
