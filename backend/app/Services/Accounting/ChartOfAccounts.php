<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Support\Tenancy\Tenant;

/** Provisions the default chart of accounts for a new company. */
final class ChartOfAccounts
{
    /** code => [name, type, system_key] */
    public const DEFAULTS = [
        '1000' => ['Cash on Hand', 'asset', 'cash'],
        '1010' => ['Bank Account', 'asset', 'bank'],
        '1020' => ['Mobile Money Wallet', 'asset', 'mobile_money'],
        '1100' => ['Loans Receivable', 'asset', 'loans_receivable'],
        '1200' => ['Accounts Receivable', 'asset', 'accounts_receivable'],
        '2100' => ['Tax Payable', 'liability', 'tax_payable'],
        '3000' => ["Owner's Equity", 'equity', 'equity'],
        '4000' => ['Interest Income', 'income', 'interest_income'],
        '4010' => ['Loan Fee Income', 'income', 'fee_income'],
        '4020' => ['Penalty Income', 'income', 'penalty_income'],
        '4100' => ['Service Income', 'income', 'service_income'],
        '4190' => ['Service Refunds (contra income)', 'income', 'service_refunds'],
        '5000' => ['Loan Write-offs', 'expense', 'loan_write_off'],
        '5100' => ['Salaries and Wages', 'expense', null],
        '5110' => ['Rent', 'expense', null],
        '5120' => ['Utilities', 'expense', null],
        '5130' => ['Transport and Travel', 'expense', null],
        '5140' => ['Office Supplies', 'expense', null],
        '5150' => ['Communication', 'expense', null],
        '5190' => ['Other Expenses', 'expense', 'other_expense'],
    ];

    public function provision(Company $company): void
    {
        Tenant::runAs($company, function (): void {
            foreach (self::DEFAULTS as $code => [$name, $type, $systemKey]) {
                $account = Account::query()->firstOrNew(['code' => (string) $code]);
                $account->fill(['name' => $name, 'type' => $type, 'is_active' => true]);
                $account->system_key = $systemKey;
                $account->save();
            }
        });
    }
}
