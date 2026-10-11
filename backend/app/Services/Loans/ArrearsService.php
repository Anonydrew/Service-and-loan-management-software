<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Loan;
use App\Support\Audit\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Keeps loan status and days-in-arrears current, and charges late penalties.
 *
 * Penalty policy: once an installment is still unpaid after its due date plus
 * the grace period, a single penalty is charged on it - a percentage of its
 * unpaid principal + interest + fees, or a fixed amount - per the loan's
 * snapshotted product terms. Penalties are income on a cash basis (recognised
 * in the ledger when paid), so charging one posts no journal entry.
 */
final class ArrearsService
{
    public function refresh(Loan $loan, ?CarbonInterface $today = null): Loan
    {
        if (! in_array($loan->status, ['active', 'in_arrears', 'closed'], true)) {
            return $loan;
        }

        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $installments = $loan->installments()->get();

        if ($installments->isNotEmpty() && $installments->every(fn ($i) => $i->status === 'paid')) {
            $loan->forceFill(['status' => 'closed', 'days_in_arrears' => 0, 'closed_at' => $loan->closed_at ?? now()])->save();

            return $loan;
        }

        $oldestOverdue = $installments
            ->filter(fn ($i) => $i->status !== 'paid' && $i->due_date->lt($today))
            ->sortBy('due_date')
            ->first();

        $days = $oldestOverdue ? (int) $oldestOverdue->due_date->diffInDays($today) : 0;

        $loan->forceFill([
            'status' => $days > 0 ? 'in_arrears' : 'active',
            'days_in_arrears' => $days,
            'closed_at' => null,
        ])->save();

        return $loan;
    }

    /** @return int total penalty charged on this loan in this run, minor units */
    public function applyPenalties(Loan $loan, ?CarbonInterface $today = null): int
    {
        if (! $loan->isOpen() || (float) $loan->penalty_value <= 0) {
            return 0;
        }

        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        return DB::transaction(function () use ($loan, $today): int {
            $charged = 0;

            $due = $loan->installments()
                ->where('status', '!=', 'paid')
                ->whereNull('penalty_applied_at')
                ->whereDate('due_date', '<', $today->subDays($loan->grace_period_days)->toDateString())
                ->get();

            foreach ($due as $installment) {
                $unpaid = ($installment->principal_due_minor - $installment->principal_paid_minor)
                    + ($installment->interest_due_minor - $installment->interest_paid_minor)
                    + ($installment->fees_due_minor - $installment->fees_paid_minor);

                if ($unpaid <= 0) {
                    continue;
                }

                $penalty = $loan->penalty_type === 'fixed'
                    ? Money::toMinor((string) $loan->penalty_value)
                    : Money::percentOf($unpaid, (string) $loan->penalty_value);

                $installment->forceFill([
                    'penalty_due_minor' => $installment->penalty_due_minor + $penalty,
                    'penalty_applied_at' => now(),
                ]);
                $installment->refreshStatus();
                $installment->save();
                $charged += $penalty;
            }

            if ($charged > 0) {
                $loan->increment('penalty_charged_minor', $charged);
                AuditLogger::log('loan.penalty_charged', $loan, "Late penalty on {$loan->loan_number}", ['amount_minor' => $charged]);
            }

            return $charged;
        });
    }
}
