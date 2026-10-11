<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LoanRepayment;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use App\Support\Money;
use App\Support\Tenancy\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $page = Payment::query()
            ->with(['customer:id,display_name,customer_number', 'invoice:id,invoice_number', 'receivedBy:id,name', 'branch:id,name'])
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method', $request->input('method')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_on', '<=', $request->date('to')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('receipt_number', 'like', $term)->orWhere('reference', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('display_name', 'like', $term)));
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($page);
    }

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(LoanRepayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $this->payments->record($invoice, Money::toMinor($data['amount']), $data['method'], $data['reference'] ?? null, $data['paid_on'], $data['notes'] ?? null);

        return response()->json($payment, 201);
    }

    public function receipt(Payment $payment): Response
    {
        $payment->load(['invoice', 'customer', 'receivedBy:id,name', 'branch']);

        return Pdf::loadView('pdf.payment-receipt', ['payment' => $payment, 'company' => Tenant::company()])
            ->setPaper('a5')
            ->download("receipt-{$payment->receipt_number}.pdf");
    }
}
