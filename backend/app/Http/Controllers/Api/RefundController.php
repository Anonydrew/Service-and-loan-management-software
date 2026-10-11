<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Billing\PaymentService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Refund::query()
                ->with(['payment:id,receipt_number,amount_minor,payment_method,customer_id,invoice_id', 'payment.customer:id,display_name',
                    'payment.invoice:id,invoice_number', 'requestedBy:id,name', 'decidedBy:id,name'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->latest('id')
                ->paginate($this->perPage($request))
        );
    }

    public function store(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->payments->requestRefund($payment, Money::toMinor($data['amount']), $data['reason']), 201);
    }

    public function decide(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:255', 'required_if:decision,reject'],
        ]);

        return response()->json($this->payments->decideRefund($refund, $data['decision'] === 'approve', $data['notes'] ?? null));
    }
}
