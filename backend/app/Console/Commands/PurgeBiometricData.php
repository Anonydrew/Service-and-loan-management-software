<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BiometricVerification;
use App\Models\Company;
use App\Services\Biometrics\BiometricService;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * Enforces the biometric retention period: once retain_until passes, provider
 * references and diagnostics are cleared (the outcome and audit trail stay)
 * and the provider is asked to delete anything it holds.
 */
class PurgeBiometricData extends Command
{
    protected $signature = 'biometrics:purge-expired';

    protected $description = 'Clear provider references from biometric verification attempts past their retention date';

    public function handle(BiometricService $biometrics): int
    {
        $count = 0;

        foreach (Company::query()->get() as $company) {
            Tenant::runAs($company, function () use ($biometrics, &$count): void {
                BiometricVerification::query()
                    ->whereNull('purged_at')
                    ->whereDate('retain_until', '<', now()->toDateString())
                    ->chunkById(200, function ($chunk) use ($biometrics, &$count): void {
                        foreach ($chunk as $attempt) {
                            $biometrics->purge($attempt);
                            $count++;
                        }
                    });
            });
        }

        $this->info("Purged {$count} biometric verification record(s).");

        return self::SUCCESS;
    }
}
