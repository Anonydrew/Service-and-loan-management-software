<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\BiometricVerification;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Operational and financial reports. Every query runs through the company and
 * branch scopes, so a branch-restricted user only ever sees their branches.
 *
 * Each report returns a uniform table shape the frontend renders and exports.
 */
final class Reports
{
    /** slug => [title, description, requires financial permission] */
    public const CATALOGUE = [
        'portfolio' => ['Loan portfolio', 'Open loans and outstanding balances by product, with portfolio at risk.', false],
        'arrears_aging' => ['Arrears ageing', 'Loans in arrears grouped by days overdue.', false],
        'disbursements' => ['Disbursements', 'Loans disbursed in the period.', false],
        'collections' => ['Collections', 'Loan repayments received in the period, split by component.', false],
        'customers' => ['Customer acquisition', 'New customers and onboarding outcomes by branch and type.', false],
        'verification' => ['Identity verification', 'Biometric verification outcomes and failure reasons (onboarding only).', false],
        'service_revenue' => ['Service revenue', 'Invoiced service revenue and collections by service.', true],
        'expenses' => ['Expenses', 'Expenses by account and branch.', true],
        'branch_performance' => ['Branch performance', 'Lending, collections, service revenue and expenses per branch.', true],
    ];

    /** SQL for a loan's outstanding balance (all components), used in aggregates. */
    public const OUTSTANDING_SQL = '(total_payable_minor + penalty_charged_minor - principal_paid_minor - interest_paid_minor - fees_paid_minor - penalty_paid_minor - written_off_minor)';

    /** @return array{title: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals?: array<string, mixed>} */
    public function run(string $slug, string $from, string $to, ?int $branchId = null): array
    {
        $method = lcfirst(str_replace('_', '', ucwords($slug, '_')));
        if (! array_key_exists($slug, self::CATALOGUE) || ! method_exists($this, $method)) {
            throw new InvalidArgumentException("Unknown report [{$slug}].");
        }

        return ['title' => self::CATALOGUE[$slug][0], 'from' => $from, 'to' => $to] + $this->{$method}($from, $to, $branchId);
    }

    private function portfolio(string $from, string $to, ?int $branchId): array
    {
        $loans = Loan::query()->whereIn('status', Loan::OPEN_STATUSES)->with('product:id,name')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get();

        $rows = $loans->groupBy('loan_product_id')->map(function (Collection $group) {
            $outstanding = $group->sum(fn (Loan $l) => $l->outstandingBalance());
            $par30 = $group->filter(fn (Loan $l) => $l->days_in_arrears > 30)->sum(fn (Loan $l) => $l->outstandingPrincipal());
            $principal = $group->sum(fn (Loan $l) => $l->outstandingPrincipal());

            return [
                'product' => $group->first()->product?->name,
                'loans' => $group->count(),
                'in_arrears' => $group->where('status', 'in_arrears')->count(),
                'principal_outstanding_minor' => $principal,
                'total_outstanding_minor' => $outstanding,
                'par30_percent' => $principal > 0 ? round($par30 / $principal * 100, 2) : 0,
            ];
        })->values();

        return $this->table([
            ['product', 'Product', 'text'], ['loans', 'Open loans', 'number'], ['in_arrears', 'In arrears', 'number'],
            ['principal_outstanding_minor', 'Principal outstanding', 'money'], ['total_outstanding_minor', 'Total outstanding', 'money'],
            ['par30_percent', 'PAR 30 %', 'percent'],
        ], $rows, ['loans', 'in_arrears', 'principal_outstanding_minor', 'total_outstanding_minor']);
    }

    private function arrearsAging(string $from, string $to, ?int $branchId): array
    {
        $loans = Loan::query()->where('status', 'in_arrears')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get();
        $buckets = ['1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], '91-180' => [91, 180], '180+' => [181, PHP_INT_MAX]];

        $rows = collect($buckets)->map(function ($range, $label) use ($loans) {
            $group = $loans->filter(fn ($l) => $l->days_in_arrears >= $range[0] && $l->days_in_arrears <= $range[1]);

            return [
                'bucket' => $label.' days',
                'loans' => $group->count(),
                'principal_outstanding_minor' => $group->sum(fn ($l) => $l->outstandingPrincipal()),
                'total_outstanding_minor' => $group->sum(fn ($l) => $l->outstandingBalance()),
            ];
        })->values();

        return $this->table([
            ['bucket', 'Days in arrears', 'text'], ['loans', 'Loans', 'number'],
            ['principal_outstanding_minor', 'Principal at risk', 'money'], ['total_outstanding_minor', 'Total outstanding', 'money'],
        ], $rows, ['loans', 'principal_outstanding_minor', 'total_outstanding_minor']);
    }

    private function disbursements(string $from, string $to, ?int $branchId): array
    {
        $rows = Loan::query()
            ->whereNotNull('disbursement_date')->whereBetween('disbursement_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['customer:id,display_name', 'product:id,name', 'branch:id,name'])
            ->orderBy('disbursement_date')->get()
            ->map(fn (Loan $l) => [
                'date' => $l->disbursement_date->toDateString(), 'loan_number' => $l->loan_number,
                'customer' => $l->customer?->display_name, 'product' => $l->product?->name, 'branch' => $l->branch?->name,
                'principal_minor' => $l->principal_minor, 'fee_minor' => $l->fee_minor, 'net_minor' => $l->net_disbursed_minor,
                'method' => $l->disbursement_method,
            ]);

        return $this->table([
            ['date', 'Date', 'date'], ['loan_number', 'Loan', 'text'], ['customer', 'Customer', 'text'], ['product', 'Product', 'text'],
            ['branch', 'Branch', 'text'], ['principal_minor', 'Principal', 'money'], ['fee_minor', 'Fees', 'money'],
            ['net_minor', 'Net paid out', 'money'], ['method', 'Method', 'text'],
        ], $rows, ['principal_minor', 'fee_minor', 'net_minor']);
    }

    private function collections(string $from, string $to, ?int $branchId): array
    {
        $rows = LoanRepayment::query()
            ->where('status', 'posted')->whereBetween('paid_on', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get()
            ->groupBy(fn ($r) => $r->paid_on->toDateString().'|'.$r->payment_method)
            ->map(function (Collection $g, string $key) {
                [$date, $method] = explode('|', $key);

                return [
                    'date' => $date, 'method' => $method, 'count' => $g->count(),
                    'amount_minor' => $g->sum('amount_minor'), 'principal_minor' => $g->sum('principal_minor'),
                    'interest_minor' => $g->sum('interest_minor'), 'fees_minor' => $g->sum('fees_minor'), 'penalty_minor' => $g->sum('penalty_minor'),
                ];
            })->sortKeys()->values();

        return $this->table([
            ['date', 'Date', 'date'], ['method', 'Method', 'text'], ['count', 'Receipts', 'number'], ['amount_minor', 'Total', 'money'],
            ['principal_minor', 'Principal', 'money'], ['interest_minor', 'Interest', 'money'], ['fees_minor', 'Fees', 'money'], ['penalty_minor', 'Penalties', 'money'],
        ], $rows, ['count', 'amount_minor', 'principal_minor', 'interest_minor', 'fees_minor', 'penalty_minor']);
    }

    private function customers(string $from, string $to, ?int $branchId): array
    {
        $rows = Customer::query()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch:id,name')->get()
            ->groupBy(fn ($c) => $c->branch?->name.'|'.$c->type)
            ->map(function (Collection $g, string $key) {
                [$branch, $type] = explode('|', $key);

                return [
                    'branch' => $branch, 'type' => $type, 'registered' => $g->count(),
                    'completed' => $g->where('onboarding_status', 'completed')->count(),
                    'in_progress' => $g->where('onboarding_status', 'in_progress')->count(),
                    'manual_review' => $g->where('onboarding_status', 'manual_review')->count(),
                    'rejected' => $g->where('onboarding_status', 'rejected')->count(),
                ];
            })->values();

        return $this->table([
            ['branch', 'Branch', 'text'], ['type', 'Type', 'text'], ['registered', 'Registered', 'number'], ['completed', 'Onboarded', 'number'],
            ['in_progress', 'In progress', 'number'], ['manual_review', 'Manual review', 'number'], ['rejected', 'Rejected', 'number'],
        ], $rows, ['registered', 'completed', 'in_progress', 'manual_review', 'rejected']);
    }

    private function verification(string $from, string $to, ?int $branchId): array
    {
        $rows = BiometricVerification::query()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereHas('customer', fn (Builder $q) => $q->when($branchId, fn ($w) => $w->where('branch_id', $branchId)))
            ->get()
            ->groupBy(fn ($a) => $a->modality.'|'.$a->status.'|'.($a->failure_reason ?? '-').'|'.($a->is_simulated ? 'yes' : 'no'))
            ->map(function (Collection $g, string $key) {
                [$modality, $status, $reason, $simulated] = explode('|', $key);

                return ['modality' => $modality, 'status' => $status, 'reason' => $reason, 'simulated' => $simulated, 'attempts' => $g->count()];
            })->values();

        return $this->table([
            ['modality', 'Method', 'text'], ['status', 'Outcome', 'text'], ['reason', 'Failure reason', 'text'],
            ['simulated', 'Simulated', 'text'], ['attempts', 'Attempts', 'number'],
        ], $rows, ['attempts']);
    }

    private function serviceRevenue(string $from, string $to, ?int $branchId): array
    {
        $invoiceIds = Invoice::query()->whereNotIn('status', ['void'])->whereBetween('issue_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->pluck('id');

        $rows = InvoiceItem::query()->whereIn('invoice_id', $invoiceIds)->with('service:id,name,category')->get()
            ->groupBy(fn ($i) => $i->service?->name ?? 'Other / manual lines')
            ->map(fn (Collection $g, string $name) => [
                'service' => $name,
                'category' => $g->first()->service?->category,
                'lines' => $g->count(),
                'net_minor' => $g->sum(fn ($i) => $i->line_total_minor - $i->tax_minor),
                'tax_minor' => $g->sum('tax_minor'),
                'gross_minor' => $g->sum('line_total_minor'),
            ])->sortByDesc('gross_minor')->values();

        $collected = Payment::query()->whereBetween('paid_on', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('amount_minor');

        $result = $this->table([
            ['service', 'Service', 'text'], ['category', 'Category', 'text'], ['lines', 'Invoiced lines', 'number'],
            ['net_minor', 'Net revenue', 'money'], ['tax_minor', 'Tax', 'money'], ['gross_minor', 'Gross', 'money'],
        ], $rows, ['lines', 'net_minor', 'tax_minor', 'gross_minor']);
        $result['notes'] = 'Payments collected on invoices in the period: GHS '.number_format($collected / 100, 2);

        return $result;
    }

    private function expenses(string $from, string $to, ?int $branchId): array
    {
        $rows = Expense::query()->where('status', 'recorded')->whereBetween('expense_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['account:id,code,name', 'branch:id,name'])->get()
            ->groupBy(fn ($e) => $e->account?->code.' '.$e->account?->name.'|'.$e->branch?->name)
            ->map(function (Collection $g, string $key) {
                [$account, $branch] = explode('|', $key);

                return ['account' => $account, 'branch' => $branch, 'count' => $g->count(), 'amount_minor' => $g->sum('amount_minor')];
            })->sortByDesc('amount_minor')->values();

        return $this->table([
            ['account', 'Account', 'text'], ['branch', 'Branch', 'text'], ['count', 'Entries', 'number'], ['amount_minor', 'Amount', 'money'],
        ], $rows, ['count', 'amount_minor']);
    }

    private function branchPerformance(string $from, string $to, ?int $branchId): array
    {
        $branches = Branch::query()->when($branchId, fn ($q) => $q->whereKey($branchId))->orderBy('name')->get();
        $range = [$from, $to];

        $rows = $branches->map(function (Branch $b) use ($range) {
            $open = Loan::query()->where('branch_id', $b->id)->whereIn('status', Loan::OPEN_STATUSES)->get();
            $principal = $open->sum(fn ($l) => $l->outstandingPrincipal());
            $par30 = $open->filter(fn ($l) => $l->days_in_arrears > 30)->sum(fn ($l) => $l->outstandingPrincipal());

            return [
                'branch' => $b->name,
                'disbursed_minor' => (int) Loan::query()->where('branch_id', $b->id)->whereBetween('disbursement_date', $range)->sum('principal_minor'),
                'collected_minor' => (int) LoanRepayment::query()->where('branch_id', $b->id)->where('status', 'posted')->whereBetween('paid_on', $range)->sum('amount_minor'),
                'service_payments_minor' => (int) Payment::query()->where('branch_id', $b->id)->whereBetween('paid_on', $range)->sum('amount_minor'),
                'expenses_minor' => (int) Expense::query()->where('branch_id', $b->id)->where('status', 'recorded')->whereBetween('expense_date', $range)->sum('amount_minor'),
                'open_loans' => $open->count(),
                'principal_outstanding_minor' => $principal,
                'par30_percent' => $principal > 0 ? round($par30 / $principal * 100, 2) : 0,
            ];
        });

        return $this->table([
            ['branch', 'Branch', 'text'], ['disbursed_minor', 'Disbursed', 'money'], ['collected_minor', 'Loan collections', 'money'],
            ['service_payments_minor', 'Service payments', 'money'], ['expenses_minor', 'Expenses', 'money'], ['open_loans', 'Open loans', 'number'],
            ['principal_outstanding_minor', 'Principal outstanding', 'money'], ['par30_percent', 'PAR 30 %', 'percent'],
        ], $rows, ['disbursed_minor', 'collected_minor', 'service_payments_minor', 'expenses_minor', 'open_loans', 'principal_outstanding_minor']);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $sum
     */
    private function table(array $columns, iterable $rows, array $sum = []): array
    {
        $rows = collect($rows)->values();

        return [
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2]], $columns),
            'rows' => $rows->all(),
            'totals' => $sum === [] ? null : collect($sum)->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all(),
        ];
    }
}
