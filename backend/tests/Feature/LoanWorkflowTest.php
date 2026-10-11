<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Loan;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoanWorkflowTest extends TestCase
{
    /** Balance of a system account as debit minus credit. */
    private function accountNet($company, string $key): int
    {
        return $this->inTenant($company, function () use ($key) {
            $id = Account::query()->where('system_key', $key)->value('id');

            return (int) JournalLine::query()->where('account_id', $id)->sum('debit_minor')
                - (int) JournalLine::query()->where('account_id', $id)->sum('credit_minor');
        });
    }

    private function assertLedgerBalanced($company): void
    {
        $this->inTenant($company, function () {
            $this->assertSame((int) JournalLine::query()->sum('debit_minor'), (int) JournalLine::query()->sum('credit_minor'));
            foreach (JournalEntry::query()->with('lines')->get() as $entry) {
                $this->assertSame($entry->lines->sum('debit_minor'), $entry->lines->sum('credit_minor'), "{$entry->entry_number} unbalanced");
            }
        });
    }

    #[Test]
    public function applications_require_a_fully_onboarded_customer(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $customer = $this->individualCustomer($company, $branch, onboarded: false);
        $product = $this->product($company);
        $this->actingAsUser($officer);

        $id = $this->postJson('/api/loan-applications', [
            'customer_id' => $customer->id, 'loan_product_id' => $product->id, 'amount' => 1000, 'term' => 4, 'purpose' => 'Stock',
        ])->assertCreated()->assertJsonPath('eligibility.eligible', false)->json('id');

        $this->postJson("/api/loan-applications/{$id}/submit")
            ->assertUnprocessable()->assertJsonPath('error_code', 'not_eligible')
            ->assertJsonPath('eligibility.checks.0.key', 'onboarding')
            ->assertJsonPath('eligibility.checks.0.status', 'fail');
    }

    #[Test]
    public function affordability_and_product_limits_are_checked(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $officer = $this->actingAsUser($this->staff($company, 'loan-officer', $branch));
        $customer = $this->individualCustomer($company, $branch, ['monthly_income_minor' => 50000]);
        $product = $this->product($company, ['max_debt_service_ratio' => '40', 'max_amount_minor' => 500000]);

        $checks = collect($this->postJson('/api/loan-applications', [
            'customer_id' => $customer->id, 'loan_product_id' => $product->id, 'amount' => 6000, 'term' => 2, 'purpose' => 'x',
        ])->assertCreated()->json('eligibility.checks'))->keyBy('key');

        $this->assertSame('fail', $checks['amount']['status']);
        $this->assertSame('fail', $checks['affordability']['status']);
    }

    #[Test]
    public function the_person_who_prepared_an_application_cannot_approve_it(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $manager = $this->actingAsUser($this->staff($company, 'branch-manager', $branch));
        $customer = $this->individualCustomer($company, $branch);
        $product = $this->product($company);

        $id = $this->postJson('/api/loan-applications', [
            'customer_id' => $customer->id, 'loan_product_id' => $product->id, 'amount' => 1000, 'term' => 4, 'purpose' => 'Stock',
        ])->json('id');
        $this->postJson("/api/loan-applications/{$id}/submit")->assertOk();

        $this->postJson("/api/loan-applications/{$id}/approve")->assertUnprocessable()->assertJsonPath('error_code', 'maker_checker');
    }

    #[Test]
    public function full_lifecycle_from_application_to_closure_keeps_the_ledger_balanced(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $manager = $this->staff($company, 'branch-manager', $branch);
        $cashier = $this->staff($company, 'cashier', $branch);
        $customer = $this->individualCustomer($company, $branch);
        $product = $this->product($company);

        // Officer prepares and submits.
        $this->actingAsUser($officer);
        $appId = $this->postJson('/api/loan-applications', [
            'customer_id' => $customer->id, 'loan_product_id' => $product->id, 'amount' => '1000.00', 'term' => 4, 'purpose' => 'Stock',
        ])->assertCreated()->json('id');
        $this->postJson("/api/loan-applications/{$appId}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertTrue($manager->fresh()->notifications()->exists(), 'approvers are notified');

        // Manager approves -> loan awaiting disbursement.
        $this->actingAsUser($manager);
        $loanId = $this->postJson("/api/loan-applications/{$appId}/approve", ['notes' => 'Good history'])->assertCreated()->json('loan.id');

        $this->actingAsUser($cashier);
        $this->postJson("/api/loans/{$loanId}/repayments", ['amount' => 10, 'method' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonPath('error_code', 'loan_not_open');

        $this->actingAsUser($manager);
        $this->postJson("/api/loans/{$loanId}/disburse", ['disbursement_date' => now()->toDateString(), 'method' => 'cash', 'agreement_signed' => false])
            ->assertUnprocessable()->assertJsonPath('error_code', 'agreement_not_signed');
        $this->postJson("/api/loans/{$loanId}/disburse", ['disbursement_date' => now()->toDateString(), 'method' => 'cash', 'agreement_signed' => true])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonCount(4, 'installments')
            ->assertJsonPath('net_disbursed_minor', 98000)->assertJsonPath('total_payable_minor', 120000);

        $this->assertSame(100000, $this->accountNet($company, 'loans_receivable'));
        $this->assertSame(-98000, $this->accountNet($company, 'cash'));
        $this->assertSame(-2000, $this->accountNet($company, 'fee_income'));

        // Cashier takes a partial payment: interest before principal, oldest first.
        $this->actingAsUser($cashier);
        $this->postJson("/api/loans/{$loanId}/repayments", ['amount' => '350.00', 'method' => 'mobile_money', 'paid_on' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson("/api/loans/{$loanId}/repayments", ['amount' => '350.00', 'method' => 'mobile_money', 'reference' => 'MP1', 'paid_on' => now()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('interest_minor', 10000)->assertJsonPath('principal_minor', 25000);

        $loan = $this->getJson("/api/loans/{$loanId}")->assertOk();
        $this->assertSame(85000, $loan->json('balances.outstanding_minor'));
        $this->assertSame('paid', $loan->json('loan.installments.0.status'));
        $this->assertSame('partial', $loan->json('loan.installments.1.status'));

        // Overpayment is refused.
        $this->postJson("/api/loans/{$loanId}/repayments", ['amount' => '900.00', 'method' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonPath('error_code', 'overpayment')->assertJsonPath('outstanding_minor', 85000);

        // Paying the rest closes the loan.
        $this->postJson("/api/loans/{$loanId}/repayments", ['amount' => '850.00', 'method' => 'cash', 'paid_on' => now()->toDateString()])->assertCreated();
        $this->assertSame('closed', $this->getJson("/api/loans/{$loanId}")->json('loan.status'));

        $this->assertSame(0, $this->accountNet($company, 'loans_receivable'));
        $this->assertSame(-20000, $this->accountNet($company, 'interest_income'));
        $this->assertLedgerBalanced($company);
    }

    #[Test]
    public function reversing_a_repayment_restores_balances_and_reopens_the_loan(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $customer = $this->individualCustomer($company, $branch);
        $loan = $this->loan($company, $customer, $this->product($company), $officer, $admin, disburseOn: now()->toDateString());

        $this->actingAsUser($admin);
        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", ['amount' => '1200.00', 'method' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertCreated()->json('id');
        $this->assertSame('closed', $loan->fresh()->status);

        $this->postJson("/api/repayments/{$repaymentId}/reverse", ['reason' => 'Bounced cheque'])->assertOk()->assertJsonPath('status', 'reversed');
        $this->postJson("/api/repayments/{$repaymentId}/reverse", ['reason' => 'again'])->assertUnprocessable();

        $fresh = $this->inTenant($company, fn () => Loan::query()->with('installments')->find($loan->id));
        $this->assertSame('active', $fresh->status);
        $this->assertSame(120000, $fresh->outstandingBalance());
        $this->assertTrue($fresh->installments->every(fn ($i) => $i->status === 'pending'));
        $this->assertSame(100000, $this->accountNet($company, 'loans_receivable'));
        $this->assertLedgerBalanced($company);
    }

    #[Test]
    public function the_daily_job_charges_late_penalties_which_are_collected_first(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $customer = $this->individualCustomer($company, $branch);
        $loan = $this->loan($company, $customer, $this->product($company), $officer, $admin, disburseOn: now()->subDays(70)->toDateString());

        Artisan::call('loans:process-daily');

        $fresh = $this->inTenant($company, fn () => Loan::query()->find($loan->id));
        $this->assertSame('in_arrears', $fresh->status);
        $this->assertGreaterThan(30, $fresh->days_in_arrears);
        // Two installments overdue x 10% of 300.00 each.
        $this->assertSame(6000, $fresh->penalty_charged_minor);

        // Running again the same day does not double-charge.
        Artisan::call('loans:process-daily');
        $this->assertSame(6000, $this->inTenant($company, fn () => Loan::query()->find($loan->id))->penalty_charged_minor);

        $this->actingAsUser($admin);
        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount' => '30.00', 'method' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('penalty_minor', 3000)->assertJsonPath('interest_minor', 0);
        $this->assertSame(-3000, $this->accountNet($company, 'penalty_income'));
    }

    #[Test]
    public function writing_off_moves_outstanding_principal_to_expense(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $customer = $this->individualCustomer($company, $branch);
        $loan = $this->loan($company, $customer, $this->product($company), $officer, $admin, disburseOn: now()->toDateString());

        $this->actingAsUser($officer);
        $this->postJson("/api/loans/{$loan->id}/write-off", ['reason' => 'x'])->assertForbidden();

        $this->actingAsUser($admin);
        $this->postJson("/api/loans/{$loan->id}/write-off", ['reason' => 'Borrower deceased'])->assertOk()->assertJsonPath('status', 'written_off');

        $this->assertSame(0, $this->accountNet($company, 'loans_receivable'));
        $this->assertSame(100000, $this->accountNet($company, 'loan_write_off'));
    }

    #[Test]
    public function editing_a_product_never_changes_existing_loans(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $product = $this->product($company);
        $loan = $this->loan($company, $this->individualCustomer($company, $branch), $product, $officer, $admin);

        $this->actingAsUser($admin);
        $this->putJson("/api/loan-products/{$product->id}", array_merge($product->toArray(), [
            'interest_rate' => 9, 'min_amount' => 100, 'max_amount' => 100000,
        ]))->assertOk();

        $this->assertEquals(5.0, (float) $this->inTenant($company, fn () => Loan::query()->find($loan->id))->interest_rate);
    }

    #[Test]
    public function schedule_preview_matches_the_disbursed_schedule(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $product = $this->product($company, ['interest_method' => 'reducing_balance', 'interest_rate' => '24', 'rate_period' => 'per_annum']);
        $this->actingAsUser($admin);

        $preview = $this->postJson('/api/loans/calculate', ['loan_product_id' => $product->id, 'amount' => 10000, 'term' => 12, 'start_date' => '2026-01-31'])
            ->assertOk();

        $this->assertSame(94560, $preview->json('installments.0.total'));
        $this->assertSame(20000, $preview->json('fee_minor'));
        $this->assertSame(980000, $preview->json('net_disbursed_minor'));
    }
}
