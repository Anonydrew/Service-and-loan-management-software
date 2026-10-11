<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Support\Collection;

/** Trial balance, income statement and balance sheet straight from the ledger. */
final class Statements
{
    /**
     * Debit and credit totals per account for entries dated within the range.
     *
     * @return Collection<int, array{account: Account, debit_minor: int, credit_minor: int, balance_minor: int}>
     */
    public function balances(?string $from = null, ?string $to = null, ?int $branchId = null): Collection
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit_minor) as debits, SUM(journal_lines.credit_minor) as credits')
            ->get()
            ->keyBy('account_id');

        return Account::query()->orderBy('code')->get()->map(function (Account $account) use ($sums): array {
            $debit = (int) ($sums[$account->id]->debits ?? 0);
            $credit = (int) ($sums[$account->id]->credits ?? 0);

            return [
                'account' => $account,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                // Natural-side balance: positive means the normal direction for the type.
                'balance_minor' => $account->isDebitNormal() ? $debit - $credit : $credit - $debit,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function trialBalance(?string $asOf = null, ?int $branchId = null): array
    {
        $rows = $this->balances(null, $asOf, $branchId)->filter(fn ($r) => $r['debit_minor'] || $r['credit_minor'])->map(function ($r) {
            $net = $r['debit_minor'] - $r['credit_minor'];

            return [
                'code' => $r['account']->code,
                'name' => $r['account']->name,
                'type' => $r['account']->type,
                'debit_minor' => max(0, $net),
                'credit_minor' => max(0, -$net),
            ];
        })->values();

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'total_debit_minor' => $rows->sum('debit_minor'),
            'total_credit_minor' => $rows->sum('credit_minor'),
        ];
    }

    /** @return array<string, mixed> */
    public function incomeStatement(string $from, string $to, ?int $branchId = null): array
    {
        $balances = $this->balances($from, $to, $branchId);
        $section = fn (string $type) => $balances->filter(fn ($r) => $r['account']->type === $type && ($r['debit_minor'] || $r['credit_minor']))
            ->map(fn ($r) => ['code' => $r['account']->code, 'name' => $r['account']->name, 'amount_minor' => $r['balance_minor']])
            ->values();

        $income = $section('income');
        $expenses = $section('expense');

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'expenses' => $expenses,
            'total_income_minor' => $income->sum('amount_minor'),
            'total_expenses_minor' => $expenses->sum('amount_minor'),
            'net_profit_minor' => $income->sum('amount_minor') - $expenses->sum('amount_minor'),
        ];
    }

    /** @return array<string, mixed> */
    public function balanceSheet(string $asOf): array
    {
        $balances = $this->balances(null, $asOf);
        $section = fn (string $type) => $balances->filter(fn ($r) => $r['account']->type === $type && ($r['debit_minor'] || $r['credit_minor']))
            ->map(fn ($r) => ['code' => $r['account']->code, 'name' => $r['account']->name, 'amount_minor' => $r['balance_minor']])
            ->values();

        $retained = $balances->filter(fn ($r) => $r['account']->type === 'income')->sum('balance_minor')
            - $balances->filter(fn ($r) => $r['account']->type === 'expense')->sum('balance_minor');

        $assets = $section('asset');
        $liabilities = $section('liability');
        $equity = $section('equity');

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained_earnings_minor' => $retained,
            'total_assets_minor' => $assets->sum('amount_minor'),
            'total_liabilities_minor' => $liabilities->sum('amount_minor'),
            'total_equity_minor' => $equity->sum('amount_minor') + $retained,
        ];
    }
}
