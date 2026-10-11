<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Services\Accounting\Ledger;
use App\Services\Messaging\CustomerNotifier;
use App\Services\Notifications\StaffNotifier;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loan lifecycle: application -> submission -> decision -> disbursement ->
 * write-off. Repayments live in RepaymentService.
 */
final class LoanService
{
    public function __construct(
        private readonly LoanCalculator $calculator,
        private readonly EligibilityChecker $eligibility,
        private readonly Ledger $ledger,
        private readonly StaffNotifier $staff,
        private readonly CustomerNotifier $customers,
    ) {}

    /** @param array<string, mixed> $data */
    public function createApplication(array $data): LoanApplication
    {
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $product = LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);

        return DB::transaction(function () use ($data, $customer, $product): LoanApplication {
            $application = new LoanApplication($data);
            $application->forceFill([
                'branch_id' => $customer->branch_id,
                'application_number' => Sequence::next('loan_application'),
                'status' => 'draft',
                'monthly_income_minor' => $data['monthly_income_minor'] ?? $customer->monthly_income_minor,
                'loan_officer_id' => $data['loan_officer_id'] ?? Tenant::user()?->id,
                'created_by' => Tenant::user()?->id,
            ]);
            $application->eligibility = $this->evaluate($application, $customer, $product);
            $application->save();

            return $application;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateApplication(LoanApplication $application, array $data): LoanApplication
    {
        $this->assertStatus($application, ['draft'], 'Only draft applications can be edited.');
        unset($data['customer_id']);

        $application->fill($data);
        $product = LoanProduct::query()->findOrFail($application->loan_product_id);
        $application->eligibility = $this->evaluate($application, $application->customer, $product);
        $application->save();

        return $application;
    }

    public function submit(LoanApplication $application): LoanApplication
    {
        $this->assertStatus($application, ['draft'], 'Only draft applications can be submitted.');

        $result = $this->evaluate($application, $application->customer, $application->product);
        $application->eligibility = $result;

        if (! $result['eligible']) {
            $application->save();
            throw new BusinessRuleException('The application does not meet the eligibility rules.', 'not_eligible', ['eligibility' => $result]);
        }

        $application->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        AuditLogger::log('loan_application.submitted', $application, "Application {$application->application_number} submitted for approval");

        $this->staff->toPermission(
            'loan_applications.approve', 'loans', 'Loan application awaiting approval',
            "{$application->application_number} for {$application->customer->display_name} - ".Money::format($application->amount_requested_minor),
            "/loans/applications/{$application->id}", $application->branch_id, Tenant::user(),
        );

        return $application;
    }

    public function approve(LoanApplication $application, ?int $amount, ?int $term, ?string $notes): Loan
    {
        $this->assertStatus($application, ['submitted'], 'Only submitted applications can be approved.');
        $this->assertNotMaker($application);

        $product = $application->product;
        $amount ??= $application->amount_requested_minor;
        $term ??= $application->term;

        if ($amount < $product->min_amount_minor || $amount > $product->max_amount_minor) {
            throw new BusinessRuleException('Approved amount is outside the product limits.', 'amount_out_of_range');
        }
        if ($term < $product->min_term || $term > $product->max_term) {
            throw new BusinessRuleException('Approved term is outside the product limits.', 'term_out_of_range');
        }

        return DB::transaction(function () use ($application, $product, $amount, $term, $notes): Loan {
            $fee = $this->calculator->fee($amount, $product->fee_type, (string) $product->fee_value);
            $preview = $this->calculator->schedule(
                $amount, (string) $product->interest_rate, $product->rate_period, $product->interest_method, $term,
                $product->repayment_frequency, now(), $product->fee_collection === 'capitalised' ? $fee : 0,
            );

            $loan = new Loan;
            $loan->forceFill([
                'branch_id' => $application->branch_id,
                'loan_number' => Sequence::next('loan'),
                'loan_application_id' => $application->id,
                'customer_id' => $application->customer_id,
                'loan_product_id' => $product->id,
                'principal_minor' => $amount,
                'interest_rate' => $product->interest_rate,
                'rate_period' => $product->rate_period,
                'interest_method' => $product->interest_method,
                'repayment_frequency' => $product->repayment_frequency,
                'term' => $term,
                'fee_collection' => $product->fee_collection,
                'fee_minor' => $fee,
                'penalty_type' => $product->penalty_type,
                'penalty_value' => $product->penalty_value,
                'grace_period_days' => $product->grace_period_days,
                'total_interest_minor' => $preview['total_interest'],
                'total_payable_minor' => $preview['total_payable'],
                'net_disbursed_minor' => $product->fee_collection === 'deducted' ? $amount - $fee : $amount,
                'status' => 'pending_disbursement',
                'loan_officer_id' => $application->loan_officer_id,
            ])->save();

            $application->forceFill([
                'status' => 'approved',
                'approved_amount_minor' => $amount,
                'approved_term' => $term,
                'decision_notes' => $notes,
                'decided_by' => Tenant::user()?->id,
                'decided_at' => now(),
            ])->save();

            AuditLogger::log('loan_application.approved', $application, "Approved {$application->application_number} as {$loan->loan_number}", [
                'approved_amount_minor' => $amount, 'approved_term' => $term,
            ]);

            $this->staff->toUser($application->createdBy, 'loans', 'Loan application approved',
                "{$application->application_number} was approved. Loan {$loan->loan_number} is ready for disbursement.", "/loans/{$loan->id}");

            DB::afterCommit(fn () => $this->customers->sms($application->customer, 'loan_approved', sprintf(
                'Dear %s, your loan application %s for %s has been approved. Please visit the branch to sign your agreement.',
                $application->customer->display_name, $application->application_number, Money::format($amount),
            )));

            return $loan;
        });
    }

    public function reject(LoanApplication $application, string $notes): LoanApplication
    {
        $this->assertStatus($application, ['submitted'], 'Only submitted applications can be rejected.');
        $this->assertNotMaker($application);

        $application->forceFill([
            'status' => 'rejected', 'decision_notes' => $notes, 'decided_by' => Tenant::user()?->id, 'decided_at' => now(),
        ])->save();
        AuditLogger::log('loan_application.rejected', $application, "Rejected {$application->application_number}", ['reason' => $notes]);
        $this->staff->toUser($application->createdBy, 'loans', 'Loan application rejected', "{$application->application_number}: {$notes}", "/loans/applications/{$application->id}");

        return $application;
    }

    public function cancel(LoanApplication $application, string $reason): LoanApplication
    {
        $this->assertStatus($application, ['draft', 'submitted'], 'Only draft or submitted applications can be cancelled.');
        $application->forceFill(['status' => 'cancelled', 'decision_notes' => $reason])->save();

        return $application;
    }

    /**
     * Generates the repayment schedule from the disbursement date, posts the
     * ledger entry and activates the loan, atomically.
     */
    public function disburse(Loan $loan, string $date, string $method, ?string $reference, bool $agreementSigned): Loan
    {
        if ($loan->status !== 'pending_disbursement') {
            throw new BusinessRuleException('Only loans awaiting disbursement can be disbursed.', 'invalid_status');
        }

        if (Tenant::company()->setting('loans.require_signed_agreement') && ! $agreementSigned) {
            throw new BusinessRuleException('Confirm the customer has signed the loan agreement before disbursing.', 'agreement_not_signed');
        }

        $customer = $loan->customer;
        if ($customer->status !== 'active') {
            throw new BusinessRuleException('The customer account is not active.', 'customer_inactive');
        }

        return DB::transaction(function () use ($loan, $date, $method, $reference): Loan {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'pending_disbursement') {
                throw new BusinessRuleException('This loan has already been disbursed.', 'invalid_status');
            }

            $start = CarbonImmutable::parse($date);
            $schedule = $this->calculator->schedule(
                $loan->principal_minor, (string) $loan->interest_rate, $loan->rate_period, $loan->interest_method,
                $loan->term, $loan->repayment_frequency, $start, $loan->fee_collection === 'capitalised' ? $loan->fee_minor : 0,
            );

            foreach ($schedule['installments'] as $row) {
                $loan->installments()->create([
                    'installment_number' => $row['number'],
                    'due_date' => $row['due_date'],
                    'principal_due_minor' => $row['principal'],
                    'interest_due_minor' => $row['interest'],
                    'fees_due_minor' => $row['fees'],
                ]);
            }

            $deductedFee = $loan->fee_collection === 'deducted' ? $loan->fee_minor : 0;
            $net = $loan->principal_minor - $deductedFee;

            $entry = $this->ledger->post("Disbursement of {$loan->loan_number}", [
                ['account' => 'loans_receivable', 'debit' => $loan->principal_minor, 'memo' => 'Principal'],
                ['account' => $this->ledger->settlementAccount($method), 'credit' => $net, 'memo' => 'Net disbursed'],
                ['account' => 'fee_income', 'credit' => $deductedFee, 'memo' => 'Processing fee deducted'],
            ], $loan, $loan->branch_id, $start);

            $loan->forceFill([
                'status' => 'active',
                'disbursement_date' => $start->toDateString(),
                'first_due_date' => $schedule['first_due_date'],
                'maturity_date' => $schedule['maturity_date'],
                'total_interest_minor' => $schedule['total_interest'],
                'total_payable_minor' => $schedule['total_payable'],
                'net_disbursed_minor' => $net,
                'disbursement_method' => $method,
                'disbursement_reference' => $reference,
                'agreement_signed_at' => now(),
                'disbursed_by' => Tenant::user()?->id,
                'disbursed_at' => now(),
            ])->save();

            $loan->application()->first()?->forceFill(['status' => 'disbursed'])->save();

            AuditLogger::log('loan.disbursed', $loan, "Disbursed {$loan->loan_number}", [
                'net_minor' => $net, 'method' => $method, 'journal' => $entry->entry_number,
            ]);

            DB::afterCommit(fn () => $this->customers->sms($loan->customer, 'loan_disbursed', sprintf(
                'Dear %s, %s has been disbursed for loan %s. First repayment of %s is due on %s.',
                $loan->customer->display_name, Money::format($net), $loan->loan_number,
                Money::format($schedule['installments'][0]['total']), $schedule['first_due_date'],
            )));

            return $loan->fresh(['installments']);
        });
    }

    public function writeOff(Loan $loan, string $reason): Loan
    {
        if (! $loan->isOpen()) {
            throw new BusinessRuleException('Only active loans or loans in arrears can be written off.', 'invalid_status');
        }

        return DB::transaction(function () use ($loan, $reason): Loan {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            $principal = $loan->outstandingPrincipal();

            if ($principal > 0) {
                $this->ledger->post("Write-off of {$loan->loan_number}", [
                    ['account' => 'loan_write_off', 'debit' => $principal],
                    ['account' => 'loans_receivable', 'credit' => $principal],
                ], $loan, $loan->branch_id);
            }

            $loan->forceFill([
                'status' => 'written_off',
                'written_off_minor' => $principal,
                'written_off_by' => Tenant::user()?->id,
                'write_off_reason' => $reason,
                'closed_at' => now(),
            ])->save();

            AuditLogger::log('loan.written_off', $loan, "Wrote off {$loan->loan_number}", ['principal_minor' => $principal, 'reason' => $reason]);

            return $loan;
        });
    }

    /** @return array<string, mixed> */
    private function evaluate(LoanApplication $application, Customer $customer, LoanProduct $product): array
    {
        return $this->eligibility->check(
            $customer, $product, (int) $application->amount_requested_minor, (int) $application->term,
            $application->monthly_income_minor, (int) $application->monthly_obligations_minor,
            $application->guarantors, $application->collateral,
        );
    }

    /** @param array<int, string> $allowed */
    private function assertStatus(LoanApplication $application, array $allowed, string $message): void
    {
        if (! in_array($application->status, $allowed, true)) {
            throw new BusinessRuleException($message, 'invalid_status');
        }
    }

    /** Maker-checker: whoever prepared an application may not decide it. */
    private function assertNotMaker(LoanApplication $application): void
    {
        if (! Tenant::company()->setting('loans.maker_checker')) {
            return;
        }

        $user = Tenant::user();
        if ($user !== null && in_array($user->id, array_filter([$application->created_by, $application->loan_officer_id]), true)) {
            throw new BusinessRuleException('You prepared this application, so another authorised user must decide it.', 'maker_checker');
        }
    }
}
