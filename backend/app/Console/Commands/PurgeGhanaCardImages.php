<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CustomerDocument;
use App\Services\Customers\DocumentService;
use App\Support\Audit\AuditLogger;
use App\Support\GhanaCard;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * L.I. 2523 forbids retaining a photocopy, scan or image of the Ghana Card.
 * Permanently deletes any Ghana Card images stored before the rule was
 * enforced in this system. Run with --dry-run first.
 */
class PurgeGhanaCardImages extends Command
{
    protected $signature = 'documents:purge-ghana-card-images {--dry-run : List what would be deleted without deleting}';

    protected $description = 'Permanently delete stored Ghana Card images ('.GhanaCard::LAW.')';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $count = 0;

        foreach (Company::query()->get() as $company) {
            Tenant::runAs($company, function () use ($dry, &$count): void {
                CustomerDocument::query()->withTrashed()
                    ->whereIn('document_type', CustomerDocument::PROHIBITED_TYPES)
                    ->chunkById(100, function ($docs) use ($dry, &$count): void {
                        foreach ($docs as $doc) {
                            $count++;
                            $this->line(($dry ? '[dry-run] ' : '')."Company {$doc->company_id}, customer {$doc->customer_id}: {$doc->file_path}");
                            if ($dry) {
                                continue;
                            }
                            Storage::disk(DocumentService::DISK)->delete($doc->file_path);
                            AuditLogger::log('customer_document.purged', $doc, 'Ghana Card image permanently deleted ('.GhanaCard::LAW.')');
                            $doc->forceDelete();
                        }
                    });
            });
        }

        $this->info(($dry ? 'Would delete ' : 'Deleted ')."{$count} Ghana Card image(s).");

        return self::SUCCESS;
    }
}
