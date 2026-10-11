<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Accounting\Ledger;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(private readonly Ledger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            JournalEntry::query()
                ->with(['lines.account:id,code,name', 'postedBy:id,name', 'branch:id,name'])
                ->when($request->filled('from'), fn ($q) => $q->whereDate('entry_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('entry_date', '<=', $request->input('to')))
                ->when($request->filled('source'), fn ($q) => $q->where('source_type', $request->input('source')))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('entry_number', 'like', '%'.$request->input('q').'%')
                    ->orWhere('description', 'like', '%'.$request->input('q').'%')))
                ->latest('id')
                ->paginate($this->perPage($request))
        );
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return response()->json($journalEntry->load(['lines.account', 'postedBy:id,name', 'branch:id,name', 'reversalOf:id,entry_number']));
    }

    /** Manual journal (adjustments, opening balances, capital injections). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $accounts = Account::query()->where('is_active', true)->whereIn('id', collect($data['lines'])->pluck('account_id'))->get()->keyBy('id');

        $lines = array_map(function ($line) use ($accounts) {
            $account = $accounts[$line['account_id']] ?? throw new BusinessRuleException('Unknown or inactive account on a journal line.', 'invalid_account');
            $debit = Money::toMinor($line['debit'] ?? 0);
            $credit = Money::toMinor($line['credit'] ?? 0);
            if ($debit > 0 && $credit > 0) {
                throw new BusinessRuleException('A journal line is either a debit or a credit, not both.', 'invalid_line');
            }

            return ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'memo' => $line['memo'] ?? null];
        }, $data['lines']);

        $entry = $this->ledger->post('Manual: '.$data['description'], $lines, null, $data['branch_id'] ?? null, CarbonImmutable::parse($data['entry_date']));
        $entry->forceFill(['source_type' => 'Manual'])->save();
        AuditLogger::log('journal.manual_posted', $entry, "Manual journal {$entry->entry_number}");

        return response()->json($entry->load('lines.account'), 201);
    }

    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        // Automatic postings are reversed through their own workflow (void the
        // invoice, reverse the repayment, ...) so the source record stays in step.
        if ($journalEntry->source_type !== 'Manual') {
            throw new BusinessRuleException('Only manual journals can be reversed here. Reverse the originating transaction instead.', 'not_manual');
        }

        $reversal = $this->ledger->reverse($journalEntry, $data['reason']);
        AuditLogger::log('journal.reversed', $journalEntry, "Reversed {$journalEntry->entry_number}", ['reason' => $data['reason']]);

        return response()->json($reversal->load('lines.account'), 201);
    }
}
