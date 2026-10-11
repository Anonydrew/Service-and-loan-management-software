<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\LoanInstallment;

/**
 * Splits a repayment across installments.
 *
 * Oldest installment first; within each installment the order is
 * penalty -> fees -> interest -> principal, so a partial payment always clears
 * the costliest arrears before reducing principal. Pure: it returns the plan
 * and does not touch the database.
 */
final class RepaymentAllocator
{
    public const ORDER = ['penalty', 'fees', 'interest', 'principal'];

    /**
     * @param  iterable<LoanInstallment>  $installments  sorted by installment_number
     * @return array{allocations: list<array{installment: LoanInstallment, principal: int, interest: int, fees: int, penalty: int}>, unallocated: int}
     */
    public function allocate(iterable $installments, int $amount): array
    {
        $remaining = $amount;
        $allocations = [];

        foreach ($installments as $installment) {
            if ($remaining <= 0) {
                break;
            }

            $split = ['principal' => 0, 'interest' => 0, 'fees' => 0, 'penalty' => 0];

            foreach (self::ORDER as $component) {
                $owed = $installment->{$component.'_due_minor'} - $installment->{$component.'_paid_minor'};
                $take = min($owed, $remaining);

                if ($take > 0) {
                    $split[$component] = $take;
                    $remaining -= $take;
                }
            }

            if (array_sum($split) > 0) {
                $allocations[] = ['installment' => $installment] + $split;
            }
        }

        return ['allocations' => $allocations, 'unallocated' => $remaining];
    }
}
