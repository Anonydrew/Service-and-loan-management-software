<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\LoanRepayment;
use App\Models\Payment;
use App\Models\Refund;
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

final class PaymentService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly StaffNotifier $staff,
        private readonly CustomerNotifier $customers,
    ) {}

    public function record(Invoice $invoice, int $amount, string $method, ?string $reference, string $paidOn, ?string $notes = null): Payment
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('Payment amount must be greater than zero.', 'invalid_amount');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $paidOn, $notes): Payment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                throw new BusinessRuleException("Payments cannot be recorded on a {$invoice->status} invoice.", 'invalid_status');
            }
            if ($amount > $invoice->balance_minor) {
                throw new BusinessRuleException('The amount exceeds the invoice balance of '.Money::format($invoice->balance_minor).'.', 'overpayment');
            }

            $payment = Payment::query()->create([
                'branch_id' => $invoice->branch_id,
                'receipt_number' => Sequence::next('payment'),
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'amount_minor' => $amount,
                'payment_method' => $method,
                'reference' => $reference,
                'paid_on' => $paidOn,
                'notes' => $notes,
                'status' => 'completed',
                'received_by' => Tenant::user()?->id,
            ]);

            $invoice->amount_paid_minor += $amount;
            $invoice->status = $invoice->amount_paid_minor >= $invoice->total_minor ? 'paid' : 'partially_paid';
            $invoice->save();

            $entry = $this->ledger->post("Payment {$payment->receipt_number} for {$invoice->invoice_number}", [
                ['account' => $this->ledger->settlementAccount($method), 'debit' => $amount],
                ['account' => 'accounts_receivable', 'credit' => $amount],
            ], $payment, $invoice->branch_id, CarbonImmutable::parse($paidOn));
            $payment->forceFill(['journal_entry_id' => $entry->id])->save();

            DB::afterCommit(fn () => $this->customers->sms($invoice->customer, 'payment_received', sprintf(
                'Payment of %s received for invoice %s. Receipt %s. Balance: %s. Thank you.',
                Money::format($amount), $invoice->invoice_number, $payment->receipt_number, Money::format($invoice->balance_minor),
            )));

            return $payment;
        });
    }

    public function requestRefund(Payment $payment, int $amount, string $reason): Refund
    {
        $pending = (int) $payment->refunds()->where('status', 'pending')->sum('amount_minor');

        if ($amount <= 0 || $amount > $payment->refundableMinor() - $pending) {
            throw new BusinessRuleException('The refund exceeds the refundable amount of '.Money::format($payment->refundableMinor() - $pending).'.', 'invalid_amount');
        }

        $refund = Refund::query()->create([
            'branch_id' => $payment->branch_id,
            'payment_id' => $payment->id,
            'amount_minor' => $amount,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => Tenant::user()?->id,
        ]);

        $this->staff->toPermission('payments.refund_approve', 'billing', 'Refund awaiting approval',
            Money::format($amount)." on {$payment->receipt_number}: {$reason}", '/billing/refunds', $payment->branch_id, Tenant::user());

        return $refund;
    }

    /** Two-person rule: the requester may not approve their own refund. */
    public function decideRefund(Refund $refund, bool $approve, ?string $notes): Refund
    {
        if ($refund->status !== 'pending') {
            throw new BusinessRuleException('This refund has already been decided.', 'already_decided');
        }
        if (Tenant::user() !== null && $refund->requested_by === Tenant::user()->id) {
            throw new BusinessRuleException('You requested this refund, so another authorised user must approve it.', 'maker_checker');
        }

        return DB::transaction(function () use ($refund, $approve, $notes): Refund {
            $refund->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => Tenant::user()?->id, 'decided_at' => now(), 'decision_notes' => $notes]);

            if ($approve) {
                $payment = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
                if ($refund->amount_minor > $payment->refundableMinor()) {
                    throw new BusinessRuleException('The payment no longer has enough refundable balance.', 'invalid_amount');
                }

                $payment->refunded_minor += $refund->amount_minor;
                $payment->status = $payment->refunded_minor >= $payment->amount_minor ? 'refunded' : 'partially_refunded';
                $payment->save();

                $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
                $invoice->amount_refunded_minor += $refund->amount_minor;
                if ($invoice->amount_refunded_minor >= $invoice->total_minor) {
                    $invoice->status = 'refunded';
                }
                $invoice->save();

                $entry = $this->ledger->post("Refund on {$payment->receipt_number}", [
                    ['account' => 'service_refunds', 'debit' => $refund->amount_minor],
                    ['account' => $this->ledger->settlementAccount($payment->payment_method), 'credit' => $refund->amount_minor],
                ], $refund, $payment->branch_id);
                $refund->journal_entry_id = $entry->id;
            }

            $refund->save();
            AuditLogger::log($approve ? 'refund.approved' : 'refund.rejected', $refund, ($approve ? 'Approved' : 'Rejected').' refund of '.Money::format($refund->amount_minor));
            $this->staff->toUser($refund->requestedBy, 'billing', $approve ? 'Refund approved' : 'Refund rejected', Money::format($refund->amount_minor).($notes ? ": {$notes}" : ''), '/billing/refunds');

            return $refund;
        });
    }

    /**
     * Marks invoice payments and loan repayments as matched to a bank, MoMo or
     * cash statement line.
     *
     * @param  array<int, int>  $paymentIds
     * @param  array<int, int>  $repaymentIds
     */
    public function reconcile(array $paymentIds, array $repaymentIds, string $statementReference): int
    {
        $values = ['reconciled_at' => now(), 'reconciled_by' => Tenant::user()?->id, 'statement_reference' => $statementReference];

        $count = DB::transaction(fn () => Payment::query()->whereIn('id', $paymentIds)->whereNull('reconciled_at')->update($values)
            + LoanRepayment::query()->whereIn('id', $repaymentIds)->where('status', 'posted')->whereNull('reconciled_at')->update($values));

        AuditLogger::log('payments.reconciled', null, "Reconciled {$count} transaction(s) to {$statementReference}", [
            'payment_ids' => $paymentIds, 'repayment_ids' => $repaymentIds,
        ]);

        return $count;
    }
}
