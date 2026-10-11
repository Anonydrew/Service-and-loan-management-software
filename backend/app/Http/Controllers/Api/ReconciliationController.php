<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanRepayment;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lists money received (invoice payments and loan repayments) for a method and
 * date range so it can be ticked off against a bank, MoMo or cash statement.
 */
class ReconciliationController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['nullable', Rule::in(LoanRepayment::METHODS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'state' => ['nullable', Rule::in(['unreconciled', 'reconciled', 'all'])],
        ]);
        $state = $data['state'] ?? 'unreconciled';

        $filter = fn ($q) => $q
            ->when($data['method'] ?? null, fn ($w, $m) => $w->where('payment_method', $m))
            ->when($data['from'] ?? null, fn ($w, $d) => $w->whereDate('paid_on', '>=', $d))
            ->when($data['to'] ?? null, fn ($w, $d) => $w->whereDate('paid_on', '<=', $d))
            ->when($state === 'unreconciled', fn ($w) => $w->whereNull('reconciled_at'))
            ->when($state === 'reconciled', fn ($w) => $w->whereNotNull('reconciled_at'));

        $payments = $filter(Payment::query())->with('customer:id,display_name')->orderBy('paid_on')->limit(500)->get()
            ->map(fn (Payment $p) => [
                'kind' => 'payment', 'id' => $p->id, 'receipt_number' => $p->receipt_number, 'customer' => $p->customer?->display_name,
                'amount_minor' => $p->amount_minor, 'method' => $p->payment_method, 'reference' => $p->reference,
                'paid_on' => $p->paid_on?->toDateString(), 'reconciled_at' => $p->reconciled_at, 'statement_reference' => $p->statement_reference,
            ]);

        $repayments = $filter(LoanRepayment::query()->where('status', 'posted'))->with('customer:id,display_name')->orderBy('paid_on')->limit(500)->get()
            ->map(fn (LoanRepayment $r) => [
                'kind' => 'repayment', 'id' => $r->id, 'receipt_number' => $r->receipt_number, 'customer' => $r->customer?->display_name,
                'amount_minor' => $r->amount_minor, 'method' => $r->payment_method, 'reference' => $r->reference,
                'paid_on' => $r->paid_on?->toDateString(), 'reconciled_at' => $r->reconciled_at, 'statement_reference' => $r->statement_reference,
            ]);

        $rows = $payments->concat($repayments)->sortBy('paid_on')->values();

        return response()->json([
            'data' => $rows,
            'totals' => ['count' => $rows->count(), 'amount_minor' => $rows->sum('amount_minor')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statement_reference' => ['required', 'string', 'max:100'],
            'payment_ids' => ['array'],
            'payment_ids.*' => ['integer'],
            'repayment_ids' => ['array'],
            'repayment_ids.*' => ['integer'],
        ]);

        $count = $this->payments->reconcile($data['payment_ids'] ?? [], $data['repayment_ids'] ?? [], $data['statement_reference']);

        return response()->json(['reconciled' => $count]);
    }
}
