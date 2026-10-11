<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a company-owned model to the bound company.
 * Fails closed: with no company bound (and no explicit bypass) it throws.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenant::bypassed()) {
            return;
        }

        if (! Tenant::has()) {
            throw MissingTenantException::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('company_id'), Tenant::id());
    }
}
