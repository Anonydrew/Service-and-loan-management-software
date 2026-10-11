<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branch-level visibility on top of company isolation. Reads are narrowed by
 * BranchScope; writes into a branch the user cannot access are refused.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::saving(function (Model $model): void {
            if (! Tenant::has() || Tenant::bypassed()) {
                return;
            }

            if ($model->isDirty('branch_id') && ! Tenant::canAccessBranch((int) $model->getAttribute('branch_id'))) {
                throw new AuthorizationException('You do not have access to that branch.');
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
