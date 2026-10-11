<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Expense;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Sequence;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ExpenseService
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @param array<string, mixed> $data */
    public function record(array $data): Expense
    {
        $account = Account::query()->where('type', 'expense')->where('is_active', true)->find($data['expense_account_id']);
        if ($account === null) {
            throw new BusinessRuleException('Choose an active expense account.', 'invalid_account');
        }

        return DB::transaction(function () use ($data, $account): Expense {
            $expense = new Expense($data);
            $expense->forceFill([
                'expense_number' => Sequence::next('expense'),
                'status' => 'recorded',
                'recorded_by' => Tenant::user()?->id,
            ])->save();

            $entry = $this->ledger->post("Expense {$expense->expense_number}: {$expense->description}", [
                ['account' => $account, 'debit' => $expense->amount_minor],
                ['account' => $this->ledger->settlementAccount($expense->payment_method), 'credit' => $expense->amount_minor],
            ], $expense, $expense->branch_id, CarbonImmutable::parse($expense->expense_date));

            $expense->forceFill(['journal_entry_id' => $entry->id])->save();

            return $expense;
        });
    }

    public function void(Expense $expense, string $reason): Expense
    {
        if ($expense->status !== 'recorded') {
            throw new BusinessRuleException('This expense is already void.', 'invalid_status');
        }

        return DB::transaction(function () use ($expense, $reason): Expense {
            if ($expense->journal_entry_id) {
                $this->ledger->reverse(\App\Models\JournalEntry::query()->findOrFail($expense->journal_entry_id), $reason);
            }

            $expense->forceFill([
                'status' => 'void', 'voided_by' => Tenant::user()?->id, 'voided_at' => now(), 'void_reason' => $reason,
            ])->save();
            AuditLogger::log('expense.voided', $expense, "Voided {$expense->expense_number}", ['reason' => $reason]);

            return $expense;
        });
    }
}
