<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\Accounting\Statements;
use App\Support\BusinessRuleException;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private readonly Statements $statements) {}

    public function index(Request $request): JsonResponse
    {
        // Staff who can only record expenses get the expense accounts, without balances.
        if (! $request->user()->hasPermission('accounting.view')) {
            return response()->json(['data' => Account::query()->where('type', 'expense')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'is_active', 'system_key'])]);
        }

        return response()->json([
            'data' => $this->statements->balances()->map(fn ($r) => $r['account']->toArray() + [
                'debit_minor' => $r['debit_minor'],
                'credit_minor' => $r['credit_minor'],
                'balance_minor' => $r['balance_minor'],
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')->where('company_id', Tenant::id())],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $account = new Account($data);
        $account->save();

        return response()->json($account, 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        if ($account->system_key !== null && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw new BusinessRuleException('System accounts are used by automatic postings and cannot be deactivated.', 'system_account');
        }

        $account->fill($data)->save();

        return response()->json($account);
    }

    public function ledger(Request $request, Account $account): JsonResponse
    {
        $lines = JournalLine::query()
            ->where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($request->filled('from'), fn ($q) => $q->where('journal_entries.entry_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('journal_entries.entry_date', '<=', $request->input('to')))
            ->orderByDesc('journal_entries.entry_date')->orderByDesc('journal_lines.id')
            ->select('journal_lines.*', 'journal_entries.entry_number', 'journal_entries.entry_date', 'journal_entries.description')
            ->paginate($this->perPage($request));

        return response()->json(['account' => $account, 'lines' => $lines]);
    }
}
