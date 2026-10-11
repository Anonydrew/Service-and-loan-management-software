<?php

declare(strict_types=1);

namespace App\Services\ServiceDesk;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Notifications\StaffNotifier;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Sequence;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

final class ServiceRequestService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly StaffNotifier $staff,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): ServiceRequest
    {
        $customer = Customer::query()->findOrFail($data['customer_id']);
        if (in_array($customer->status, ['rejected', 'closed', 'suspended'], true)) {
            throw new BusinessRuleException("This customer's account is {$customer->status}.", 'customer_inactive');
        }

        $service = Service::query()->where('is_active', true)->findOrFail($data['service_id']);
        $this->assertAssignee($data['assigned_to'] ?? null);

        return DB::transaction(function () use ($data, $customer, $service): ServiceRequest {
            $request = new ServiceRequest($data);
            $request->forceFill([
                'branch_id' => $data['branch_id'] ?? $customer->branch_id,
                'request_number' => Sequence::next('service_request'),
                'unit_price_minor' => $service->price_is_variable && isset($data['unit_price_minor']) ? $data['unit_price_minor'] : $service->price_minor,
                'status' => ! empty($data['scheduled_at']) ? 'scheduled' : 'new',
                'created_by' => Tenant::user()?->id,
            ])->save();

            $this->log($request, null, $request->status, 'Request created');
            $this->notifyAssignee($request);

            return $request;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ServiceRequest $request, array $data): ServiceRequest
    {
        $this->assertNotTerminal($request);
        $this->assertAssignee($data['assigned_to'] ?? null);

        if (isset($data['unit_price_minor']) && ! $request->service->price_is_variable) {
            unset($data['unit_price_minor']);
        }
        unset($data['customer_id'], $data['service_id']);

        $previousAssignee = $request->assigned_to;
        $request->fill($data);

        if (in_array($request->status, ['new', 'on_hold'], true) && $request->scheduled_at !== null && $request->isDirty('scheduled_at')) {
            $this->log($request, $request->status, 'scheduled', 'Appointment scheduled for '.$request->scheduled_at->format('d M Y H:i'));
            $request->status = 'scheduled';
        }
        $request->save();

        if ($request->assigned_to !== $previousAssignee) {
            $this->log($request, null, null, 'Assigned to '.($request->assignee?->name ?? 'nobody'));
            $this->notifyAssignee($request);
        }

        return $request;
    }

    public function transition(ServiceRequest $request, string $status, ?string $note = null, bool $createInvoice = true): ServiceRequest
    {
        if (! $request->canTransitionTo($status)) {
            throw new BusinessRuleException("A {$request->status} request cannot move to {$status}.", 'invalid_transition');
        }

        if ($status === 'cancelled' && blank($note)) {
            throw new BusinessRuleException('Give a reason for cancelling the request.', 'reason_required');
        }

        return DB::transaction(function () use ($request, $status, $note, $createInvoice): ServiceRequest {
            $from = $request->status;
            $request->status = $status;

            if ($status === 'completed') {
                $request->forceFill(['completed_at' => now(), 'completed_by' => Tenant::user()?->id, 'completion_notes' => $note]);
            }
            if ($status === 'cancelled') {
                $request->cancel_reason = $note;
            }
            $request->save();
            $this->log($request, $from, $status, $note);

            if ($status === 'completed' && $createInvoice && $request->total_minor > 0 && $request->invoice_id === null) {
                $invoice = $this->invoices->fromServiceRequest($request);
                $request->forceFill(['invoice_id' => $invoice->id])->save();
            }

            return $request;
        });
    }

    public function invoice(ServiceRequest $request): Invoice
    {
        if ($request->invoice_id !== null) {
            throw new BusinessRuleException('This request has already been invoiced.', 'already_invoiced');
        }
        if ($request->status === 'cancelled') {
            throw new BusinessRuleException('Cancelled requests cannot be invoiced.', 'invalid_status');
        }

        return DB::transaction(function () use ($request): Invoice {
            $invoice = $this->invoices->fromServiceRequest($request);
            $request->forceFill(['invoice_id' => $invoice->id])->save();
            $this->log($request, null, null, "Invoice {$invoice->invoice_number} issued");

            return $invoice;
        });
    }

    private function log(ServiceRequest $request, ?string $from, ?string $to, ?string $note): void
    {
        $request->updates()->create([
            'user_id' => Tenant::user()?->id,
            'status_from' => $from,
            'status_to' => $to,
            'note' => $note,
        ]);

        if ($to !== null && $from !== null) {
            AuditLogger::log('service_request.status_changed', $request, "{$request->request_number}: {$from} -> {$to}");
        }
    }

    private function notifyAssignee(ServiceRequest $request): void
    {
        if ($request->assigned_to && $request->assigned_to !== Tenant::user()?->id) {
            $this->staff->toUser($request->assignee, 'services', 'Service request assigned to you',
                "{$request->request_number}: {$request->service->name} for {$request->customer->display_name}", "/services/requests/{$request->id}");
        }
    }

    private function assertAssignee(mixed $userId): void
    {
        if ($userId !== null && ! User::query()->inCompany()->whereKey($userId)->where('status', 'active')->exists()) {
            throw new BusinessRuleException('The assignee must be an active member of staff.', 'invalid_assignee');
        }
    }

    private function assertNotTerminal(ServiceRequest $request): void
    {
        if (in_array($request->status, ['completed', 'cancelled'], true)) {
            throw new BusinessRuleException('Completed or cancelled requests cannot be edited.', 'invalid_status');
        }
    }
}
