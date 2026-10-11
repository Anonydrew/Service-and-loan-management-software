<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Marks a model as company-owned: scopes every query and stamps company_id on
 * insert from the bound context - never from request input.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if (Tenant::has() && ! Tenant::bypassed()) {
                $model->setAttribute('company_id', Tenant::id());

                return;
            }

            // Seeders and system jobs with no context must name the company.
            if ($model->getAttribute('company_id') === null) {
                throw MissingTenantException::forModel($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw new RuntimeException('A record cannot be moved between companies.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
