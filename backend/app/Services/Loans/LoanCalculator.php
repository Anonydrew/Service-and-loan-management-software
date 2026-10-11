<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Pure loan arithmetic: fees and repayment schedules. All amounts are integer
 * minor units; intermediate maths uses bcmath at 20 decimal places and rounds
 * half up once per figure. The final installment absorbs rounding so totals
 * always reconcile exactly to the principal.
 */
final class LoanCalculator
{
    private const SCALE = 20;

    private const PERIODS_PER_YEAR = ['weekly' => 52, 'biweekly' => 26, 'monthly' => 12];

    /** Interest rate per installment period, as a decimal fraction string. */
    public function periodicRate(string $rate, string $ratePeriod, string $frequency): string
    {
        $periods = self::PERIODS_PER_YEAR[$frequency] ?? throw new InvalidArgumentException("Unknown frequency [{$frequency}].");
        $fraction = bcdiv($rate, '100', self::SCALE);

        $annual = match ($ratePeriod) {
            'per_annum' => $fraction,
            'per_month' => bcmul($fraction, '12', self::SCALE),
            default => throw new InvalidArgumentException("Unknown rate period [{$ratePeriod}]."),
        };

        return bcdiv($annual, (string) $periods, self::SCALE);
    }

    public function fee(int $principal, string $feeType, string $feeValue): int
    {
        return $feeType === 'fixed' ? Money::toMinor($feeValue) : Money::percentOf($principal, $feeValue);
    }

    /**
     * @return array{
     *     installments: list<array{number: int, due_date: string, principal: int, interest: int, fees: int, total: int}>,
     *     total_principal: int, total_interest: int, total_fees: int, total_payable: int,
     *     first_due_date: string, maturity_date: string, periodic_rate: string
     * }
     */
    public function schedule(
        int $principal,
        string $rate,
        string $ratePeriod,
        string $method,
        int $term,
        string $frequency,
        CarbonInterface $start,
        int $capitalisedFees = 0,
    ): array {
        if ($principal <= 0 || $term <= 0) {
            throw new InvalidArgumentException('Principal and term must be positive.');
        }

        $r = $this->periodicRate($rate, $ratePeriod, $frequency);

        $rows = match ($method) {
            'flat' => $this->flat($principal, $r, $term),
            'reducing_balance' => $this->reducing($principal, $r, $term),
            default => throw new InvalidArgumentException("Unknown interest method [{$method}]."),
        };

        $fees = $this->spread($capitalisedFees, $term);
        $startDate = CarbonImmutable::parse($start->toDateString());
        $installments = [];
        $totals = ['principal' => 0, 'interest' => 0, 'fees' => 0];

        foreach ($rows as $i => [$p, $int]) {
            $n = $i + 1;
            $installments[] = [
                'number' => $n,
                'due_date' => $this->dueDate($startDate, $frequency, $n)->toDateString(),
                'principal' => $p,
                'interest' => $int,
                'fees' => $fees[$i],
                'total' => $p + $int + $fees[$i],
            ];
            $totals['principal'] += $p;
            $totals['interest'] += $int;
            $totals['fees'] += $fees[$i];
        }

        return [
            'installments' => $installments,
            'total_principal' => $totals['principal'],
            'total_interest' => $totals['interest'],
            'total_fees' => $totals['fees'],
            'total_payable' => $totals['principal'] + $totals['interest'] + $totals['fees'],
            'first_due_date' => $installments[0]['due_date'],
            'maturity_date' => $installments[$term - 1]['due_date'],
            'periodic_rate' => $r,
        ];
    }

    public function dueDate(CarbonImmutable $start, string $frequency, int $n): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $start->addWeeks($n),
            'biweekly' => $start->addWeeks(2 * $n),
            'monthly' => $start->addMonthsNoOverflow($n),
            default => throw new InvalidArgumentException("Unknown frequency [{$frequency}]."),
        };
    }

    /**
     * Flat: interest is charged on the original principal for every period.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function flat(int $principal, string $r, int $term): array
    {
        $totalInterest = (int) Money::roundHalfUp(bcmul(bcmul((string) $principal, $r, self::SCALE), (string) $term, self::SCALE));
        $principals = $this->spread($principal, $term);
        $interests = $this->spread($totalInterest, $term);

        return array_map(null, $principals, $interests);
    }

    /**
     * Reducing balance (amortising): equal installments, interest on the
     * outstanding balance each period.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function reducing(int $principal, string $r, int $term): array
    {
        if (bccomp($r, '0', self::SCALE) === 0) {
            return array_map(fn ($p) => [$p, 0], $this->spread($principal, $term));
        }

        // payment = P * r / (1 - (1 + r)^-n)
        $growth = bcpow(bcadd('1', $r, self::SCALE), (string) $term, self::SCALE);
        $discount = bcsub('1', bcdiv('1', $growth, self::SCALE), self::SCALE);
        $payment = (int) Money::roundHalfUp(bcdiv(bcmul((string) $principal, $r, self::SCALE), $discount, self::SCALE));

        $rows = [];
        $balance = $principal;

        for ($i = 1; $i <= $term; $i++) {
            $interest = (int) Money::roundHalfUp(bcmul((string) $balance, $r, self::SCALE));
            $principalPart = $i === $term ? $balance : min($balance, max(0, $payment - $interest));
            $rows[] = [$principalPart, $interest];
            $balance -= $principalPart;
        }

        return $rows;
    }

    /**
     * Splits an amount into $parts near-equal integers; the remainder goes on
     * the final part so the sum is exact.
     *
     * @return list<int>
     */
    private function spread(int $amount, int $parts): array
    {
        $base = intdiv($amount, $parts);
        $result = array_fill(0, $parts, $base);
        $result[$parts - 1] += $amount - ($base * $parts);

        return $result;
    }
}
