<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Services\Accounting\Ledger;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

final class InvoiceService
{
    public function __construct(private readonly Ledger $ledger) {}

    public function fromServiceRequest(ServiceRequest $request): Invoice
    {
        $service = $request->service;

        return $this->create($request->customer, [[
            'service_id' => $service->id,
            'description' => $service->name.($request->description ? ' - '.str($request->description)->limit(120) : ''),
            'quantity' => (string) $request->quantity,
            'unit_price_minor' => $request->unit_price_minor,
            'discount_minor' => $request->discount_minor,
            'tax_rate' => (string) $service->tax_rate,
        ]], null, "Service request {$request->request_number}", $request->branch_id, $request);
    }

    /**
     * @param  array<int, array{service_id?: int|null, description: string, quantity: string|int|float, unit_price_minor: int, discount_minor?: int, tax_rate?: string|int|float|null}>  $items
     */
    public function create(
        Customer $customer,
        array $items,
        ?string $dueDate = null,
        ?string $notes = null,
        ?int $branchId = null,
        ?ServiceRequest $request = null,
    ): Invoice {
        if ($items === []) {
            throw new BusinessRuleException('An invoice needs at least one line.', 'no_items');
        }

        $lines = [];
        $subtotal = $discount = $tax = 0;

        foreach ($items as $item) {
            $serviceId = $item['service_id'] ?? null;
            if ($serviceId !== null && ! Service::query()->whereKey($serviceId)->exists()) {
                throw new BusinessRuleException('Unknown service on invoice line.', 'invalid_service');
            }

            $qty = (string) $item['quantity'];
            $gross = (int) Money::roundHalfUp(bcmul((string) $item['unit_price_minor'], $qty, 4));
            $lineDiscount = min((int) ($item['discount_minor'] ?? 0), $gross);
            $rate = (string) ($item['tax_rate'] ?? '0');
            $lineTax = Money::percentOf($gross - $lineDiscount, $rate);

            $lines[] = [
                'service_id' => $serviceId,
                'description' => $item['description'],
                'quantity' => $qty,
                'unit_price_minor' => (int) $item['unit_price_minor'],
                'discount_minor' => $lineDiscount,
                'tax_rate' => $rate,
                'tax_minor' => $lineTax,
                'line_total_minor' => $gross - $lineDiscount + $lineTax,
            ];
            $subtotal += $gross;
            $discount += $lineDiscount;
            $tax += $lineTax;
        }

        $total = $subtotal - $discount + $tax;
        if ($total <= 0) {
            throw new BusinessRuleException('Invoice total must be greater than zero.', 'invalid_total');
        }

        return DB::transaction(function () use ($customer, $lines, $subtotal, $discount, $tax, $total, $dueDate, $notes, $branchId, $request): Invoice {
            $invoice = Invoice::query()->create([
                'branch_id' => $branchId ?? $customer->branch_id,
                'invoice_number' => Sequence::next('invoice'),
                'customer_id' => $customer->id,
                'service_request_id' => $request?->id,
                'issue_date' => now()->toDateString(),
                'due_date' => $dueDate ?? now()->addDays((int) Tenant::company()->setting('billing.invoice_due_days'))->toDateString(),
                'status' => 'issued',
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discount,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'notes' => $notes,
                'issued_by' => Tenant::user()?->id,
            ]);
            $invoice->items()->createMany($lines);

            $entry = $this->ledger->post("Invoice {$invoice->invoice_number}", [
                ['account' => 'accounts_receivable', 'debit' => $total],
                ['account' => 'service_income', 'credit' => $subtotal - $discount],
                ['account' => 'tax_payable', 'credit' => $tax],
            ], $invoice, $invoice->branch_id);

            $invoice->forceFill(['journal_entry_id' => $entry->id])->save();

            return $invoice;
        });
    }

    public function void(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status !== 'issued' || $invoice->amount_paid_minor > 0) {
            throw new BusinessRuleException('Only unpaid invoices can be voided. Refund payments first.', 'invalid_status');
        }

        return DB::transaction(function () use ($invoice, $reason): Invoice {
            if ($invoice->journal_entry_id) {
                $this->ledger->reverse($invoice->journalEntry()->firstOrFail(), "Void: {$reason}");
            }

            $invoice->forceFill([
                'status' => 'void',
                'voided_by' => Tenant::user()?->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            ServiceRequest::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            AuditLogger::log('invoice.voided', $invoice, "Voided {$invoice->invoice_number}", ['reason' => $reason]);

            return $invoice;
        });
    }
}
