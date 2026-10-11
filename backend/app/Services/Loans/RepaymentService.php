<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanRepayment;
use App\Services\Accounting\Ledger;
use App\Services\Messaging\CustomerNotifier;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RepaymentService
{
    public function __construct(
        private readonly RepaymentAllocator $allocator,
        private readonly ArrearsService $arrears,
        private readonly Ledger $ledger,
        private readonly CustomerNotifier $customers,
    ) {}

    public function record(Loan $loan, int $amount, string $method, ?string $reference, string $paidOn, ?string $notes = null): LoanRepayment
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('Repayment amount must be greater than zero.', 'invalid_amount');
        }

        return DB::transaction(function () use ($loan, $amount, $method, $reference, $paidOn, $notes): LoanRepayment {
            // Lock the loan so two cashiers cannot allocate against the same balance.
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if (! $loan->isOpen()) {
                throw new BusinessRuleException('Repayments can only be recorded against active loans.', 'loan_not_open');
            }

            $installments = $loan->installments()->where('status', '!=', 'paid')->get();
            $plan = $this->allocator->allocate($installments, $amount);

            if ($plan['unallocated'] > 0) {
                throw new BusinessRuleException(
                    'The amount exceeds the outstanding balance of '.Money::format($amount - $plan['unallocated']).'.',
                    'overpayment',
                    ['outstanding_minor' => $amount - $plan['unallocated']],
                );
            }

            $totals = ['principal' => 0, 'interest' => 0, 'fees' => 0, 'penalty' => 0];
            foreach ($plan['allocations'] as $a) {
                foreach ($totals as $k => $_) {
                    $totals[$k] += $a[$k];
                }
            }

            $repayment = LoanRepayment::query()->create([
                'branch_id' => $loan->branch_id,
                'receipt_number' => Sequence::next('repayment'),
                'loan_id' => $loan->id,
                'customer_id' => $loan->customer_id,
                'amount_minor' => $amount,
                'principal_minor' => $totals['principal'],
                'interest_minor' => $totals['interest'],
                'fees_minor' => $totals['fees'],
                'penalty_minor' => $totals['penalty'],
                'payment_method' => $method,
                'reference' => $reference,
                'paid_on' => $paidOn,
                'notes' => $notes,
                'status' => 'posted',
                'received_by' => Tenant::user()?->id,
            ]);

            foreach ($plan['allocations'] as $a) {
                /** @var LoanInstallment $installment */
                $installment = $a['installment'];
                $repayment->allocations()->create([
                    'loan_installment_id' => $installment->id,
                    'principal_minor' => $a['principal'],
                    'interest_minor' => $a['interest'],
                    'fees_minor' => $a['fees'],
                    'penalty_minor' => $a['penalty'],
                ]);
                $installment->principal_paid_minor += $a['principal'];
                $installment->interest_paid_minor += $a['interest'];
                $installment->fees_paid_minor += $a['fees'];
                $installment->penalty_paid_minor += $a['penalty'];
                $installment->refreshStatus();
                $installment->save();
            }

            $loan->principal_paid_minor += $totals['principal'];
            $loan->interest_paid_minor += $totals['interest'];
            $loan->fees_paid_minor += $totals['fees'];
            $loan->penalty_paid_minor += $totals['penalty'];
            $loan->save();

            $entry = $this->ledger->post("Repayment {$repayment->receipt_number} on {$loan->loan_number}", [
                ['account' => $this->ledger->settlementAccount($method), 'debit' => $amount],
                ['account' => 'loans_receivable', 'credit' => $totals['principal'], 'memo' => 'Principal'],
                ['account' => 'interest_income', 'credit' => $totals['interest'], 'memo' => 'Interest'],
                ['account' => 'fee_income', 'credit' => $totals['fees'], 'memo' => 'Fees'],
                ['account' => 'penalty_income', 'credit' => $totals['penalty'], 'memo' => 'Penalty'],
            ], $repayment, $loan->branch_id, CarbonImmutable::parse($paidOn));

            $repayment->forceFill(['journal_entry_id' => $entry->id])->save();
            $this->arrears->refresh($loan);

            DB::afterCommit(fn () => $this->customers->sms($loan->customer, 'repayment_received', sprintf(
                'Payment received: %s for loan %s. Receipt %s. Outstanding balance: %s. Thank you.',
                Money::format($amount), $loan->loan_number, $repayment->receipt_number, Money::format($loan->fresh()->outstandingBalance()),
            )));

            return $repayment;
        });
    }

    /** Undoes exactly what the repayment allocated and reverses its journal entry. */
    public function reverse(LoanRepayment $repayment, string $reason): LoanRepayment
    {
        return DB::transaction(function () use ($repayment, $reason): LoanRepayment {
            $repayment = LoanRepayment::query()->lockForUpdate()->findOrFail($repayment->id);
            if ($repayment->status !== 'posted') {
                throw new BusinessRuleException('This repayment has already been reversed.', 'already_reversed');
            }

            $loan = Loan::query()->lockForUpdate()->findOrFail($repayment->loan_id);
            if (in_array($loan->status, ['written_off', 'cancelled'], true)) {
                throw new BusinessRuleException('Repayments on written-off loans cannot be reversed.', 'invalid_status');
            }

            foreach ($repayment->allocations()->with('installment')->get() as $allocation) {
                $installment = $allocation->installment;
                $installment->principal_paid_minor -= $allocation->principal_minor;
                $installment->interest_paid_minor -= $allocation->interest_minor;
                $installment->fees_paid_minor -= $allocation->fees_minor;
                $installment->penalty_paid_minor -= $allocation->penalty_minor;
                $installment->refreshStatus();
                $installment->save();
            }

            $loan->principal_paid_minor -= $repayment->principal_minor;
            $loan->interest_paid_minor -= $repayment->interest_minor;
            $loan->fees_paid_minor -= $repayment->fees_minor;
            $loan->penalty_paid_minor -= $repayment->penalty_minor;
            if ($loan->status === 'closed') {
                $loan->status = 'active';
                $loan->closed_at = null;
            }
            $loan->save();

            if ($repayment->journal_entry_id) {
                $this->ledger->reverse($repayment->journalEntry()->firstOrFail(), $reason);
            }

            $repayment->forceFill([
                'status' => 'reversed',
                'reversed_by' => Tenant::user()?->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            $this->arrears->refresh($loan);
            AuditLogger::log('loan_repayment.reversed', $repayment, "Reversed {$repayment->receipt_number}", ['reason' => $reason]);

            return $repayment;
        });
    }
}
