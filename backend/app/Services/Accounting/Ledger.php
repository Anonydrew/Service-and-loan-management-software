<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\BusinessRuleException;
use App\Support\Sequence;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only way money reaches the books. Every entry must balance; posting
 * happens inside the caller's transaction so the business event and its
 * ledger entry commit or fail together.
 */
final class Ledger
{
    /** Which asset account each payment method settles into. */
    public const METHOD_ACCOUNTS = [
        'cash' => 'cash',
        'mobile_money' => 'mobile_money',
        'bank_transfer' => 'bank',
        'cheque' => 'bank',
        'card' => 'bank',
    ];

    /** @var array<string, Account> */
    private array $accounts = [];

    public function account(string $systemKey): Account
    {
        $cacheKey = Tenant::id().':'.$systemKey;

        return $this->accounts[$cacheKey] ??= Account::query()->where('system_key', $systemKey)->first()
            ?? throw new LogicException("System account [{$systemKey}] is missing for this company.");
    }

    public function settlementAccount(string $paymentMethod): Account
    {
        return $this->account(self::METHOD_ACCOUNTS[$paymentMethod] ?? 'cash');
    }

    /**
     * @param  array<int, array{account: Account|string, debit?: int, credit?: int, memo?: string|null}>  $lines
     */
    public function post(
        string $description,
        array $lines,
        ?Model $source = null,
        ?int $branchId = null,
        ?CarbonInterface $date = null,
        ?int $reversalOfId = null,
    ): JournalEntry {
        $prepared = [];
        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new LogicException('Journal line amounts cannot be negative.');
            }

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $account = $line['account'] instanceof Account ? $line['account'] : $this->account($line['account']);
            $debits += $debit;
            $credits += $credit;
            $prepared[] = [
                'account_id' => $account->id,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'memo' => $line['memo'] ?? null,
            ];
        }

        if ($prepared === []) {
            throw new LogicException('A journal entry needs at least one non-zero line.');
        }

        if ($debits !== $credits) {
            throw new BusinessRuleException(
                "Journal entry does not balance (debits {$debits}, credits {$credits}).",
                'journal_unbalanced',
            );
        }

        return DB::transaction(function () use ($description, $prepared, $source, $branchId, $date, $debits, $reversalOfId): JournalEntry {
            $entry = JournalEntry::query()->create([
                'branch_id' => $branchId,
                'entry_number' => Sequence::next('journal'),
                'entry_date' => ($date ?? now())->toDateString(),
                'description' => $description,
                'source_type' => $source ? class_basename($source) : null,
                'source_id' => $source?->getKey(),
                'total_minor' => $debits,
                'reversal_of_id' => $reversalOfId,
                'posted_by' => Tenant::user()?->id,
            ]);

            $entry->lines()->createMany($prepared);

            return $entry;
        });
    }

    /** Posts the mirror image of an entry and marks the original reversed. */
    public function reverse(JournalEntry $entry, string $reason, ?CarbonInterface $date = null): JournalEntry
    {
        if ($entry->reversed_at !== null) {
            throw new BusinessRuleException('This journal entry has already been reversed.', 'already_reversed');
        }

        $lines = $entry->lines()->get()->map(fn ($line) => [
            'account' => $line->account()->first(),
            'debit' => $line->credit_minor,
            'credit' => $line->debit_minor,
            'memo' => $line->memo,
        ])->all();

        $reversal = $this->post(
            "Reversal of {$entry->entry_number}: {$reason}",
            $lines,
            null,
            $entry->branch_id,
            $date,
            $entry->id,
        );

        $reversal->forceFill(['source_type' => $entry->source_type, 'source_id' => $entry->source_id])->save();
        $entry->forceFill(['reversed_at' => now()])->save();

        return $reversal;
    }
}
