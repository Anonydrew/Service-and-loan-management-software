<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Loan;
use App\Services\Loans\ArrearsService;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily: charges late penalties and refreshes arrears status for every open
 * loan, company by company (each in its own tenant context).
 */
class ProcessLoansDaily extends Command
{
    protected $signature = 'loans:process-daily {--date= : Process as of this date (YYYY-MM-DD)}';

    protected $description = 'Apply late penalties and update days-in-arrears for open loans';

    public function handle(ArrearsService $arrears): int
    {
        $today = $this->option('date') ? now()->parse($this->option('date')) : now();
        $penalties = 0;
        $loans = 0;

        foreach (Company::query()->where('status', 'active')->get() as $company) {
            Tenant::runAs($company, function () use ($arrears, $today, &$penalties, &$loans): void {
                Loan::query()->whereIn('status', Loan::OPEN_STATUSES)->chunkById(200, function ($chunk) use ($arrears, $today, &$penalties, &$loans): void {
                    foreach ($chunk as $loan) {
                        try {
                            $penalties += $arrears->applyPenalties($loan, $today);
                            $arrears->refresh($loan->fresh(), $today);
                            $loans++;
                        } catch (Throwable $e) {
                            report($e);
                            $this->error("Loan {$loan->loan_number}: {$e->getMessage()}");
                        }
                    }
                });
            });
        }

        $this->info(sprintf('Processed %d loan(s); penalties charged GHS %s.', $loans, number_format($penalties / 100, 2)));

        return self::SUCCESS;
    }
}
