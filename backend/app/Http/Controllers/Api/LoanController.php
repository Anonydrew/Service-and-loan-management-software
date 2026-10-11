<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Services\Loans\LoanService;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class LoanController extends Controller
{
    public function __construct(private readonly LoanService $loans) {}

    public function index(Request $request): JsonResponse
    {
        $page = Loan::query()
            ->with(['customer:id,display_name,customer_number,phone', 'product:id,name', 'branch:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('loan_product_id', $request->integer('product_id')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('loan_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('display_name', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        $page->getCollection()->each(fn (Loan $l) => $l->setAttribute('outstanding_minor', $l->outstandingBalance()));

        return response()->json($page);
    }

    public function show(Loan $loan): JsonResponse
    {
        $loan->load([
            'customer', 'product:id,name,code', 'branch:id,name', 'application:id,application_number,purpose',
            'installments', 'loanOfficer:id,name', 'disbursedBy:id,name',
            'collectionActivities.user:id,name',
        ]);
        $repayments = LoanRepayment::query()->where('loan_id', $loan->id)->with('receivedBy:id,name')->latest('id')->get();

        return response()->json([
            'loan' => $loan,
            'repayments' => $repayments,
            'balances' => [
                'outstanding_minor' => $loan->outstandingBalance(),
                'outstanding_principal_minor' => $loan->outstandingPrincipal(),
                'overdue_minor' => $loan->installments
                    ->filter(fn ($i) => $i->status !== 'paid' && $i->due_date->isPast())
                    ->sum(fn ($i) => $i->balance_minor),
                'next_installment' => $loan->installments->first(fn ($i) => $i->status !== 'paid'),
            ],
        ]);
    }

    public function disburse(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'disbursement_date' => ['required', 'date', 'before_or_equal:today', 'after:-60 days'],
            'method' => ['required', Rule::in(LoanRepayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'agreement_signed' => ['required', 'boolean'],
        ]);

        return response()->json($this->loans->disburse($loan, $data['disbursement_date'], $data['method'], $data['reference'] ?? null, $data['agreement_signed']));
    }

    public function writeOff(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->loans->writeOff($loan, $data['reason']));
    }

    public function agreement(Loan $loan): Response
    {
        $loan->load(['customer.representatives', 'product', 'branch', 'installments']);
        AuditLogger::log('loan.agreement_generated', $loan, "Generated agreement for {$loan->loan_number}");

        return Pdf::loadView('pdf.loan-agreement', ['loan' => $loan, 'company' => Tenant::company()])
            ->download("loan-agreement-{$loan->loan_number}.pdf");
    }
}
