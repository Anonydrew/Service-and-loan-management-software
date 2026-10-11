<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Support\Money;

/**
 * Evaluates an application against product rules and the customer's standing.
 * A "fail" blocks submission; a "warn" is shown to the approver.
 */
final class EligibilityChecker
{
    public function __construct(private readonly LoanCalculator $calculator) {}

    /**
     * @param  array<int, mixed>|null  $guarantors
     * @param  array<int, mixed>|null  $collateral
     * @return array{eligible: bool, checks: list<array{key: string, label: string, status: string, detail: string}>, installment_minor: int, monthly_installment_minor: int}
     */
    public function check(
        Customer $customer,
        LoanProduct $product,
        int $amount,
        int $term,
        ?int $monthlyIncome,
        int $monthlyObligations = 0,
        ?array $guarantors = null,
        ?array $collateral = null,
        ?int $excludeApplicationLoanId = null,
    ): array {
        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail) use (&$checks): void {
            $checks[] = compact('key', 'label', 'status', 'detail');
        };

        $onboarded = $customer->status === 'active' && $customer->onboarding_status === 'completed';
        $add('onboarding', 'Customer onboarding complete', $onboarded ? 'pass' : 'fail',
            $onboarded ? 'Identity verified and onboarding completed.' : 'Complete onboarding and identity verification before applying.');

        $typeOk = $product->allowsCustomerType($customer->type);
        $add('customer_type', 'Product available to this customer type', $typeOk ? 'pass' : 'fail',
            $typeOk ? 'Allowed.' : "This product is not offered to {$customer->type} customers.");

        $amountOk = $amount >= $product->min_amount_minor && $amount <= $product->max_amount_minor;
        $add('amount', 'Amount within product limits', $amountOk ? 'pass' : 'fail', sprintf(
            'Allowed range %s - %s.', Money::format($product->min_amount_minor), Money::format($product->max_amount_minor),
        ));

        $termOk = $term >= $product->min_term && $term <= $product->max_term;
        $add('term', 'Term within product limits', $termOk ? 'pass' : 'fail',
            "Allowed {$product->min_term}-{$product->max_term} {$product->repayment_frequency} installments.");

        if ($customer->isIndividual() && ($product->min_age !== null || $product->max_age !== null)) {
            $age = $customer->age();
            if ($age === null) {
                $add('age', 'Age requirement', 'warn', 'Date of birth not recorded.');
            } else {
                $ageOk = ($product->min_age === null || $age >= $product->min_age) && ($product->max_age === null || $age <= $product->max_age);
                $add('age', 'Age requirement', $ageOk ? 'pass' : 'fail', "Customer is {$age}.");
            }
        }

        $openLoans = Loan::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['pending_disbursement', 'active', 'in_arrears'])
            ->when($excludeApplicationLoanId, fn ($q) => $q->whereKeyNot($excludeApplicationLoanId))
            ->get(['id', 'status']);
        $activeOk = $openLoans->count() < $product->max_active_loans;
        $add('active_loans', 'Active loan limit', $activeOk ? 'pass' : 'fail',
            "{$openLoans->count()} open loan(s); product allows {$product->max_active_loans}.");

        $inArrears = $openLoans->contains(fn ($l) => $l->status === 'in_arrears');
        $add('arrears', 'No loans in arrears', $inArrears ? 'fail' : 'pass',
            $inArrears ? 'Customer has a loan in arrears.' : 'No arrears.');

        $estimate = $this->calculator->schedule(
            max(1, $amount), (string) $product->interest_rate, $product->rate_period, $product->interest_method,
            max(1, $term), $product->repayment_frequency, now(),
            $product->fee_collection === 'capitalised' ? $this->calculator->fee($amount, $product->fee_type, (string) $product->fee_value) : 0,
        );
        $installment = $estimate['installments'][0]['total'];
        $monthly = match ($product->repayment_frequency) {
            'weekly' => intdiv($installment * 52, 12),
            'biweekly' => intdiv($installment * 26, 12),
            default => $installment,
        };

        if ($product->max_debt_service_ratio !== null) {
            if (! $monthlyIncome) {
                $add('affordability', 'Affordability (debt service ratio)', 'warn', 'Monthly income not provided.');
            } else {
                $ratio = round((($monthly + $monthlyObligations) / $monthlyIncome) * 100, 1);
                $limit = (float) $product->max_debt_service_ratio;
                $add('affordability', 'Affordability (debt service ratio)', $ratio <= $limit ? 'pass' : 'fail',
                    "Repayments would take {$ratio}% of monthly income; limit is {$limit}%.");
            }
        }

        if ($product->requires_guarantor) {
            $has = ! empty($guarantors);
            $add('guarantor', 'Guarantor provided', $has ? 'pass' : 'fail', $has ? count($guarantors).' guarantor(s).' : 'This product requires a guarantor.');
        }

        if ($product->requires_collateral) {
            $has = ! empty($collateral);
            $add('collateral', 'Collateral recorded', $has ? 'pass' : 'fail', $has ? count($collateral).' item(s).' : 'This product requires collateral.');
        }

        return [
            'eligible' => ! collect($checks)->contains(fn ($c) => $c['status'] === 'fail'),
            'checks' => $checks,
            'installment_minor' => $installment,
            'monthly_installment_minor' => $monthly,
        ];
    }
}
