<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use App\Support\Permissions;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a ready-to-use company: head-office branch, default roles, chart of
 * accounts and the first administrator.
 */
final class CompanyProvisioner
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /** @return array{company: Company, branch: Branch, admin: User} */
    public function provision(string $name, string $adminName, string $adminEmail, string $adminPassword, string $branchName = 'Head Office'): array
    {
        return DB::transaction(function () use ($name, $adminName, $adminEmail, $adminPassword, $branchName): array {
            $company = new Company(['name' => $name]);
            $company->slug = Str::slug($name).'-'.Str::lower(Str::random(5));
            $company->save();

            $this->chart->provision($company);

            return Tenant::runAs($company, function () use ($company, $adminName, $adminEmail, $adminPassword, $branchName): array {
                $branch = new Branch(['name' => $branchName, 'code' => 'HQ', 'is_head_office' => true, 'is_active' => true]);
                $branch->save();

                $this->syncRoles();

                $admin = new User([
                    'name' => $adminName,
                    'email' => strtolower($adminEmail),
                    'password' => $adminPassword,
                    'job_title' => 'Administrator',
                    'primary_branch_id' => $branch->id,
                ]);
                $admin->company_id = $company->id;
                $admin->status = 'active';
                $admin->email_verified_at = now();
                $admin->save();
                $admin->roles()->sync([Role::query()->where('slug', 'administrator')->value('id')]);

                return ['company' => $company, 'branch' => $branch, 'admin' => $admin];
            });
        });
    }

    /** Creates missing default roles and keeps the Administrator role complete. */
    public function syncRoles(): void
    {
        foreach (Permissions::defaultRoles() as $slug => $definition) {
            $role = Role::query()->firstOrNew(['slug' => $slug]);

            if (! $role->exists || $slug === 'administrator') {
                $role->fill($definition + ['slug' => $slug]);
                $role->is_system = true;
                $role->save();
            }
        }
    }
}
