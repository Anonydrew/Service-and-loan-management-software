<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Services\Loans\RepaymentService;
use App\Support\Money;
use App\Support\Tenancy\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RepaymentController extends Controller
{
    public function __construct(private readonly RepaymentService $repayments) {}

    public function index(Request $request): JsonResponse
    {
        $page = LoanRepayment::query()
            ->with(['customer:id,display_name,customer_number', 'loan:id,loan_number', 'receivedBy:id,name', 'branch:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method', $request->input('method')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_on', '<=', $request->date('to')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('receipt_number', 'like', $term)->orWhere('reference', 'like', $term)
                    ->orWhereHas('loan', fn ($l) => $l->where('loan_number', 'like', $term)));
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($page);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(LoanRepayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $repayment = $this->repayments->record($loan, Money::toMinor($data['amount']), $data['method'], $data['reference'] ?? null, $data['paid_on'], $data['notes'] ?? null);

        return response()->json($repayment->load('allocations'), 201);
    }

    public function reverse(Request $request, LoanRepayment $repayment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->repayments->reverse($repayment, $data['reason']));
    }

    public function receipt(LoanRepayment $repayment): Response
    {
        $repayment->load(['loan', 'customer', 'receivedBy:id,name', 'branch']);

        return Pdf::loadView('pdf.repayment-receipt', [
            'repayment' => $repayment,
            'company' => Tenant::company(),
            'outstanding' => $repayment->loan->outstandingBalance(),
        ])->setPaper('a5')->download("receipt-{$repayment->receipt_number}.pdf");
    }
}
