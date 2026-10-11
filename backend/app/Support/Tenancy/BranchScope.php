<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows queries to the acting user's branches. Users holding the
 * branches.all permission (branchIds() === null) are not narrowed.
 */
final class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenant::bypassed() || ! Tenant::has()) {
            return;
        }

        $branchIds = Tenant::branchIds();

        if ($branchIds !== null) {
            $builder->whereIn($model->qualifyColumn('branch_id'), $branchIds);
        }
    }
}
