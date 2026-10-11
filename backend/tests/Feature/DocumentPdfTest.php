<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentPdfTest extends TestCase
{
    #[Test]
    public function agreements_invoices_and_receipts_render_as_pdf(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $customer = $this->individualCustomer($company, $branch);
        $loan = $this->loan($company, $customer, $this->product($company), $officer, $admin, disburseOn: now()->toDateString());
        $this->actingAsUser($admin);

        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", ['amount' => 100, 'method' => 'cash', 'paid_on' => now()->toDateString()])->json('id');
        $invoiceId = $this->postJson('/api/invoices', ['customer_id' => $customer->id, 'items' => [['description' => 'Advice', 'quantity' => 1, 'unit_price' => 50]]])->json('id');
        $paymentId = $this->postJson("/api/invoices/{$invoiceId}/payments", ['amount' => 50, 'method' => 'cash', 'paid_on' => now()->toDateString()])->json('id');

        foreach ([
            "/api/loans/{$loan->id}/agreement",
            "/api/repayments/{$repaymentId}/receipt",
            "/api/invoices/{$invoiceId}/pdf",
            "/api/payments/{$paymentId}/receipt",
        ] as $url) {
            $response = $this->get($url, ['Accept' => '*/*']);
            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent(), "{$url} is not a PDF");
            $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        }
    }
}
