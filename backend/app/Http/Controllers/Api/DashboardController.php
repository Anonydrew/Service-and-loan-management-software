<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanInstallment;
use App\Models\LoanRepayment;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Reporting\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Operational summary. Each section appears only for users permitted to see it. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $out = ['generated_at' => now()->toIso8601String(), 'alerts' => []];

        if ($user->hasPermission('customers.view')) {
            $out['customers'] = [
                'active' => Customer::query()->where('status', 'active')->count(),
                'new_this_month' => Customer::query()->where('created_at', '>=', $monthStart)->count(),
                'onboarding_in_progress' => Customer::query()->where('onboarding_status', 'in_progress')->count(),
                'manual_review' => Customer::query()->where('onboarding_status', 'manual_review')->count(),
            ];
            if ($out['customers']['manual_review'] > 0 && $user->hasPermission('onboarding.review')) {
                $out['alerts'][] = ['level' => 'warning', 'text' => "{$out['customers']['manual_review']} customer(s) awaiting manual identity review", 'link' => '/customers/review'];
            }
        }

        if ($user->hasPermission('loans.view')) {
            $open = Loan::query()->whereIn('status', Loan::OPEN_STATUSES);
            $principal = (int) (clone $open)->sum(DB::raw('principal_minor - principal_paid_minor - written_off_minor'));
            $par30 = (int) (clone $open)->where('days_in_arrears', '>', 30)->sum(DB::raw('principal_minor - principal_paid_minor - written_off_minor'));

            $out['loans'] = [
                'active' => (clone $open)->count(),
                'in_arrears' => (clone $open)->where('status', 'in_arrears')->count(),
                'outstanding_minor' => (int) (clone $open)->sum(DB::raw(Reports::OUTSTANDING_SQL)),
                'principal_outstanding_minor' => $principal,
                'par30_percent' => $principal > 0 ? round($par30 / $principal * 100, 2) : 0,
                'disbursed_this_month_minor' => (int) Loan::query()->where('disbursement_date', '>=', $monthStart)->sum('principal_minor'),
                'collected_this_month_minor' => (int) LoanRepayment::query()->where('status', 'posted')->where('paid_on', '>=', $monthStart)->sum('amount_minor'),
                'pending_approval' => LoanApplication::query()->where('status', 'submitted')->count(),
                'pending_disbursement' => Loan::query()->where('status', 'pending_disbursement')->count(),
                'due_today_minor' => (int) LoanInstallment::query()
                    ->whereIn('loan_id', Loan::query()->whereIn('status', Loan::OPEN_STATUSES)->select('id'))
                    ->whereDate('due_date', $today)->where('status', '!=', 'paid')
                    ->get()->sum(fn ($i) => $i->balance_minor),
            ];

            $out['trend'] = $this->trend();

            if ($out['loans']['pending_approval'] > 0 && $user->hasPermission('loan_applications.approve')) {
                $out['alerts'][] = ['level' => 'info', 'text' => "{$out['loans']['pending_approval']} loan application(s) awaiting approval", 'link' => '/loans/applications?status=submitted'];
            }
            if ($out['loans']['pending_disbursement'] > 0 && $user->hasPermission('loans.disburse')) {
                $out['alerts'][] = ['level' => 'info', 'text' => "{$out['loans']['pending_disbursement']} approved loan(s) ready to disburse", 'link' => '/loans?status=pending_disbursement'];
            }
            if ($out['loans']['in_arrears'] > 0 && $user->hasPermission('collections.view')) {
                $out['alerts'][] = ['level' => 'danger', 'text' => "{$out['loans']['in_arrears']} loan(s) in arrears", 'link' => '/loans/arrears'];
            }
        }

        if ($user->hasPermission('service_requests.view')) {
            $out['services'] = [
                'open' => ServiceRequest::query()->whereNotIn('status', ['completed', 'cancelled'])->count(),
                'assigned_to_me' => ServiceRequest::query()->whereNotIn('status', ['completed', 'cancelled'])->where('assigned_to', $user->id)->count(),
                'completed_this_month' => ServiceRequest::query()->where('status', 'completed')->where('completed_at', '>=', $monthStart)->count(),
                'scheduled_today' => ServiceRequest::query()->where('status', 'scheduled')->whereDate('scheduled_at', $today)->count(),
            ];
        }

        if ($user->hasPermission('invoices.view')) {
            $openInvoices = Invoice::query()->whereIn('status', ['issued', 'partially_paid']);
            $out['billing'] = [
                'receivables_minor' => (int) (clone $openInvoices)->sum(DB::raw('total_minor - amount_paid_minor')),
                'overdue_invoices' => (clone $openInvoices)->whereDate('due_date', '<', $today)->count(),
                'payments_this_month_minor' => (int) Payment::query()->where('paid_on', '>=', $monthStart)->sum('amount_minor'),
            ];
        }

        if ($user->hasPermission('payments.refund_approve')) {
            $pending = Refund::query()->where('status', 'pending')->count();
            if ($pending > 0) {
                $out['alerts'][] = ['level' => 'warning', 'text' => "{$pending} refund(s) awaiting approval", 'link' => '/billing/refunds'];
            }
        }

        if ($user->hasPermission('repayments.view')) {
            $out['recent_repayments'] = LoanRepayment::query()->where('status', 'posted')
                ->with(['customer:id,display_name', 'loan:id,loan_number'])->latest('id')->limit(6)
                ->get(['id', 'receipt_number', 'loan_id', 'customer_id', 'amount_minor', 'payment_method', 'paid_on']);
        }

        return response()->json($out);
    }

    /** Disbursements vs collections for the last six months. */
    private function trend(): array
    {
        $start = now()->startOfMonth()->subMonths(5);
        $months = collect(range(0, 5))->mapWithKeys(fn ($i) => [$start->copy()->addMonths($i)->format('Y-m') => ['disbursed_minor' => 0, 'collected_minor' => 0]]);

        foreach (Loan::query()->where('disbursement_date', '>=', $start->toDateString())->get(['disbursement_date', 'principal_minor']) as $l) {
            $key = $l->disbursement_date->format('Y-m');
            if ($months->has($key)) {
                $months[$key] = ['disbursed_minor' => $months[$key]['disbursed_minor'] + $l->principal_minor] + $months[$key];
            }
        }
        foreach (LoanRepayment::query()->where('status', 'posted')->where('paid_on', '>=', $start->toDateString())->get(['paid_on', 'amount_minor']) as $r) {
            $key = $r->paid_on->format('Y-m');
            if ($months->has($key)) {
                $months[$key] = ['collected_minor' => $months[$key]['collected_minor'] + $r->amount_minor] + $months[$key];
            }
        }

        return $months->map(fn ($v, $k) => ['month' => $k] + $v)->values()->all();
    }
}
