<?php

namespace Tests\Feature;

use App\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function permissions_are_enforced_on_the_server_not_just_the_menu(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $cashier = $this->actingAsUser($this->staff($company, 'cashier', $branch));

        $this->getJson('/api/customers')->assertOk();
        $this->postJson('/api/loan-products', ['name' => 'X'])->assertForbidden()->assertJsonPath('error_code', 'forbidden');
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->getJson('/api/settings')->assertForbidden();
        $this->postJson('/api/customers', [])->assertForbidden();
        $this->getJson('/api/statements/trial-balance')->assertForbidden();
        $this->assertFalse($cashier->hasPermission('loan_applications.approve'));
    }

    #[Test]
    public function forbidden_users_learn_nothing_about_record_existence(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $this->actingAsUser($this->staff($company, 'cashier', $branch));

        // 403 for both a real and a non-existent id: permission runs before binding.
        $this->postJson('/api/loan-applications/999999/approve')->assertForbidden();
    }

    #[Test]
    public function the_auditor_role_is_read_only(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $this->actingAsUser($this->staff($company, 'auditor', $branch));

        $this->getJson('/api/loans')->assertOk();
        $this->getJson('/api/invoices')->assertOk();
        $this->postJson('/api/services', ['name' => 'X', 'code' => 'X', 'price' => 1])->assertForbidden();
        $this->postJson('/api/expenses', [])->assertForbidden();
    }

    #[Test]
    public function users_cannot_grant_permissions_they_do_not_hold(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $manager = $this->staff($company, 'branch-manager', $branch);
        // Give the manager role-management rights, but not settings.manage.
        $this->inTenant($company, function () use ($manager) {
            $role = Role::query()->where('slug', 'branch-manager')->first();
            $role->permissions = array_merge($role->permissions, ['roles.manage', 'users.manage']);
            $role->save();
        });
        $this->actingAsUser($manager);

        $this->postJson('/api/roles', ['name' => 'Super', 'permissions' => ['settings.manage']])
            ->assertUnprocessable()->assertJsonPath('error_code', 'privilege_escalation');

        $adminRoleId = $this->inTenant($company, fn () => Role::query()->where('slug', 'administrator')->value('id'));
        $this->postJson('/api/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => 'Secret-Pass-123',
            'primary_branch_id' => $branch->id, 'role_ids' => [$adminRoleId],
        ])->assertUnprocessable()->assertJsonPath('error_code', 'privilege_escalation');
    }

    #[Test]
    public function disabled_users_are_signed_out_on_their_next_request(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $user = $this->staff($company, 'cashier', $branch);
        $user->forceFill(['status' => 'disabled'])->save();
        $this->actingAsUser($user);

        $this->getJson('/api/customers')->assertUnauthorized()->assertJsonPath('error_code', 'account_disabled');
    }
}
