<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalLine;
use App\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceBillingTest extends TestCase
{
    private function net($company, string $key): int
    {
        return $this->inTenant($company, function () use ($key) {
            $id = Account::query()->where('system_key', $key)->value('id');

            return (int) JournalLine::query()->where('account_id', $id)->sum('debit_minor') - (int) JournalLine::query()->where('account_id', $id)->sum('credit_minor');
        });
    }

    #[Test]
    public function completing_a_service_request_issues_a_taxed_invoice_that_can_be_paid_in_parts(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $serviceOfficer = $this->staff($company, 'service-officer', $branch);
        $cashier = $this->staff($company, 'cashier', $branch);
        $customer = $this->individualCustomer($company, $branch);
        $service = $this->inTenant($company, fn () => Service::query()->create([
            'name' => 'Tax Filing', 'code' => 'TAX', 'price_minor' => 20000, 'tax_rate' => '15', 'is_active' => true,
        ]));

        $this->actingAsUser($serviceOfficer);
        $id = $this->postJson('/api/service-requests', [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'quantity' => 2, 'discount' => '40.00',
            'assigned_to' => $cashier->id, 'unit_price' => '1.00', // ignored: price is not variable
        ])->assertCreated()->assertJsonPath('status', 'new')->assertJsonPath('unit_price_minor', 20000)->json('id');
        $this->assertTrue($cashier->fresh()->notifications()->exists(), 'assignee is notified');

        $this->postJson("/api/service-requests/{$id}/status", ['status' => 'completed'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'invalid_transition');
        $this->postJson("/api/service-requests/{$id}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/service-requests/{$id}/status", ['status' => 'completed', 'note' => 'Filed with GRA'])->assertOk();

        $invoiceId = $this->getJson("/api/service-requests/{$id}")->assertOk()->json('invoice.id');
        $this->assertNotNull($invoiceId);

        // 2 x 200.00 = 400.00, less 40.00 discount = 360.00, + 15% tax = 54.00 => 414.00
        $invoice = $this->getJson("/api/invoices/{$invoiceId}")->assertOk();
        $invoice->assertJsonPath('subtotal_minor', 40000)->assertJsonPath('discount_minor', 4000)
            ->assertJsonPath('tax_minor', 5400)->assertJsonPath('total_minor', 41400)->assertJsonPath('status', 'issued');

        $this->assertSame(41400, $this->net($company, 'accounts_receivable'));
        $this->assertSame(-36000, $this->net($company, 'service_income'));
        $this->assertSame(-5400, $this->net($company, 'tax_payable'));

        $this->actingAsUser($cashier);
        $this->postJson("/api/invoices/{$invoiceId}/payments", ['amount' => '200.00', 'method' => 'cash', 'paid_on' => now()->toDateString()])->assertCreated();
        $this->getJson("/api/invoices/{$invoiceId}")->assertJsonPath('status', 'partially_paid')->assertJsonPath('balance_minor', 21400);

        $this->postJson("/api/invoices/{$invoiceId}/payments", ['amount' => '300.00', 'method' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonPath('error_code', 'overpayment');
        $this->postJson("/api/invoices/{$invoiceId}/payments", ['amount' => '214.00', 'method' => 'bank_transfer', 'reference' => 'GCB1', 'paid_on' => now()->toDateString()])->assertCreated();
        $this->getJson("/api/invoices/{$invoiceId}")->assertJsonPath('status', 'paid');

        $this->assertSame(0, $this->net($company, 'accounts_receivable'));
        $this->assertSame(20000, $this->net($company, 'cash'));
        $this->assertSame(21400, $this->net($company, 'bank'));
    }

    #[Test]
    public function refunds_need_a_second_person_and_paid_invoices_cannot_be_voided(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $cashier = $this->staff($company, 'cashier', $branch);
        $accountant = $this->staff($company, 'accountant', $branch);
        $customer = $this->individualCustomer($company, $branch);

        $this->actingAsUser($admin);
        $invoiceId = $this->postJson('/api/invoices', ['customer_id' => $customer->id, 'items' => [
            ['description' => 'Consultation', 'quantity' => 1, 'unit_price' => '100.00'],
        ]])->assertCreated()->json('id');

        $this->actingAsUser($cashier);
        $paymentId = $this->postJson("/api/invoices/{$invoiceId}/payments", ['amount' => '100.00', 'method' => 'cash', 'paid_on' => now()->toDateString()])->json('id');

        $this->actingAsUser($admin);
        $this->postJson("/api/invoices/{$invoiceId}/void", ['reason' => 'error'])->assertUnprocessable()->assertJsonPath('error_code', 'invalid_status');

        $this->actingAsUser($cashier);
        $this->postJson("/api/payments/{$paymentId}/refunds", ['amount' => '150.00', 'reason' => 'too much'])->assertUnprocessable();
        $refundId = $this->postJson("/api/payments/{$paymentId}/refunds", ['amount' => '100.00', 'reason' => 'Service not delivered'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson("/api/refunds/{$refundId}/decide", ['decision' => 'approve'])->assertForbidden();

        // Admin holds both permissions but requested nothing; accountant approves.
        $this->actingAsUser($accountant);
        $this->postJson("/api/refunds/{$refundId}/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'approved');

        $this->actingAsUser($admin);
        $this->getJson("/api/invoices/{$invoiceId}")->assertJsonPath('status', 'refunded')->assertJsonPath('payments.0.status', 'refunded');
        $this->assertSame(0, $this->net($company, 'cash'));
        $this->assertSame(10000, $this->net($company, 'service_refunds'));
    }

    #[Test]
    public function requesters_cannot_approve_their_own_refunds(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch);
        $this->actingAsUser($admin);

        $invoiceId = $this->postJson('/api/invoices', ['customer_id' => $customer->id, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 50]]])->json('id');
        $paymentId = $this->postJson("/api/invoices/{$invoiceId}/payments", ['amount' => 50, 'method' => 'cash', 'paid_on' => now()->toDateString()])->json('id');
        $refundId = $this->postJson("/api/payments/{$paymentId}/refunds", ['amount' => 50, 'reason' => 'x'])->json('id');

        $this->postJson("/api/refunds/{$refundId}/decide", ['decision' => 'approve'])->assertUnprocessable()->assertJsonPath('error_code', 'maker_checker');
    }

    #[Test]
    public function unpaid_invoices_can_be_voided_which_reverses_the_ledger(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch);
        $this->actingAsUser($admin);

        $invoiceId = $this->postJson('/api/invoices', ['customer_id' => $customer->id, 'items' => [['description' => 'X', 'quantity' => 3, 'unit_price' => '10.00', 'tax_rate' => 15]]])
            ->assertJsonPath('total_minor', 3450)->json('id');
        $this->postJson("/api/invoices/{$invoiceId}/void", ['reason' => 'Raised in error'])->assertOk()->assertJsonPath('status', 'void');

        $this->assertSame(0, $this->net($company, 'accounts_receivable'));
        $this->assertSame(0, $this->net($company, 'service_income'));
    }

    #[Test]
    public function reconciliation_marks_payments_and_repayments_against_a_statement(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch);
        $this->actingAsUser($admin);
        $invoiceId = $this->postJson('/api/invoices', ['customer_id' => $customer->id, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 50]]])->json('id');
        $paymentId = $this->postJson("/api/invoices/{$invoiceId}/payments", ['amount' => 50, 'method' => 'mobile_money', 'reference' => 'M1', 'paid_on' => now()->toDateString()])->json('id');

        $this->getJson('/api/reconciliation?method=mobile_money')->assertOk()->assertJsonPath('totals.count', 1);
        $this->postJson('/api/reconciliation', ['statement_reference' => 'MOMO-STMT-OCT', 'payment_ids' => [$paymentId]])->assertOk()->assertJsonPath('reconciled', 1);
        $this->getJson('/api/reconciliation?method=mobile_money')->assertJsonPath('totals.count', 0);
    }

    #[Test]
    public function expenses_post_to_the_ledger_and_can_be_voided(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $this->actingAsUser($admin);
        $rent = $this->inTenant($company, fn () => Account::query()->where('code', '5110')->first());

        $id = $this->postJson('/api/expenses', [
            'branch_id' => $branch->id, 'expense_account_id' => $rent->id, 'payment_method' => 'bank_transfer',
            'amount' => '1500.00', 'expense_date' => now()->toDateString(), 'description' => 'October rent',
        ])->assertCreated()->json('id');

        $this->assertSame(-150000, $this->net($company, 'bank'));
        $statement = $this->getJson('/api/statements/income')->assertOk();
        $this->assertSame(-150000, $statement->json('net_profit_minor'));

        $this->postJson("/api/expenses/{$id}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->assertSame(0, $this->net($company, 'bank'));
        $this->getJson('/api/statements/trial-balance')->assertOk()->assertJson(fn ($json) => $json->where('total_debit_minor', $json->toArray()['total_credit_minor'])->etc());
    }
}
