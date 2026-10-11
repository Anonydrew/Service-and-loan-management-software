<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use App\Support\Money;
use App\Support\Tenancy\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $page = Invoice::query()
            ->with(['customer:id,display_name,customer_number', 'branch:id,name'])
            ->when($request->filled('status'), function ($q) use ($request): void {
                $request->input('status') === 'overdue'
                    ? $q->whereIn('status', ['issued', 'partially_paid'])->whereDate('due_date', '<', now())
                    : $q->where('status', $request->input('status'));
            })
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('invoice_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('display_name', 'like', $term)));
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($page);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load([
            'customer', 'items', 'branch:id,name', 'issuedBy:id,name', 'serviceRequest:id,request_number',
            'payments.receivedBy:id,name', 'payments.refunds',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.service_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $customer = Customer::query()->findOrFail($data['customer_id']);
        $items = array_map(fn ($i) => [
            'service_id' => $i['service_id'] ?? null,
            'description' => $i['description'],
            'quantity' => (string) $i['quantity'],
            'unit_price_minor' => Money::toMinor($i['unit_price']),
            'discount_minor' => Money::toMinor($i['discount'] ?? 0),
            'tax_rate' => (string) ($i['tax_rate'] ?? 0),
        ], $data['items']);

        $invoice = $this->invoices->create($customer, $items, $data['due_date'] ?? null, $data['notes'] ?? null);

        return response()->json($invoice->load('items'), 201);
    }

    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->invoices->void($invoice, $data['reason']));
    }

    public function pdf(Invoice $invoice): Response
    {
        $invoice->load(['customer', 'items', 'branch', 'payments']);

        return Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'company' => Tenant::company()])
            ->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
