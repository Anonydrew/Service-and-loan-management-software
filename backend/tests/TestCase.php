<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Customers\ConsentService;
use App\Services\Customers\CustomerService;
use App\Services\Loans\LoanService;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    /** @return array{company: Company, branch: Branch, admin: User} */
    protected function newCompany(string $name = 'Test Finance'): array
    {
        return app(CompanyProvisioner::class)->provision($name, 'Admin '.$name, 'admin'.(++$this->sequence).'@example.test', 'Secret-Pass-123');
    }

    protected function inTenant(Company $company, Closure $callback, ?User $user = null): mixed
    {
        return Tenant::runAs($company, $callback, $user);
    }

    protected function branch(Company $company, string $name = 'Second Branch'): Branch
    {
        return $this->inTenant($company, fn () => Branch::query()->create([
            'name' => $name, 'code' => 'B'.(++$this->sequence), 'is_active' => true,
        ]));
    }

    /** @param array<int, Branch> $extraBranches */
    protected function staff(Company $company, string $roleSlug, Branch $branch, array $extraBranches = []): User
    {
        return $this->inTenant($company, function () use ($company, $roleSlug, $branch, $extraBranches): User {
            $user = new User([
                'name' => ucfirst($roleSlug).' '.(++$this->sequence),
                'email' => $roleSlug.$this->sequence.'@example.test',
                'password' => 'Secret-Pass-123',
                'primary_branch_id' => $branch->id,
            ]);
            $user->company_id = $company->id;
            $user->status = 'active';
            $user->save();
            $user->roles()->sync([Role::query()->where('slug', $roleSlug)->value('id')]);
            $user->branches()->sync(collect($extraBranches)->pluck('id'));

            return $user;
        });
    }

    protected function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    protected function individualCustomer(Company $company, Branch $branch, array $overrides = [], bool $onboarded = true, ?User $actor = null): Customer
    {
        return $this->inTenant($company, function () use ($branch, $overrides, $onboarded): Customer {
            $customer = app(CustomerService::class)->create($overrides + [
                'type' => 'individual', 'branch_id' => $branch->id, 'first_name' => 'Ama', 'last_name' => 'Owusu'.(++$this->sequence),
                'date_of_birth' => '1990-05-05', 'phone' => '+23324'.str_pad((string) $this->sequence, 7, '0', STR_PAD_LEFT),
                'id_type' => 'ghana_card', 'id_number' => 'GHA-'.str_pad((string) $this->sequence, 9, '0', STR_PAD_LEFT).'-1',
                'monthly_income_minor' => 1000000,
            ]);

            if ($onboarded) {
                app(ConsentService::class)->record($customer, 'data_processing', true, 'signed_form');
                $customer->forceFill(['status' => 'active', 'onboarding_status' => 'completed', 'verification_status' => 'verified'])->save();
            }

            return $customer->fresh();
        }, $actor);
    }

    /** @param array<string, mixed> $overrides */
    protected function product(Company $company, array $overrides = []): LoanProduct
    {
        return $this->inTenant($company, fn () => LoanProduct::query()->create($overrides + [
            'name' => 'Test Loan', 'code' => 'TL'.(++$this->sequence), 'customer_type' => 'both',
            'interest_method' => 'flat', 'interest_rate' => '5', 'rate_period' => 'per_month',
            'repayment_frequency' => 'monthly', 'min_amount_minor' => 10000, 'max_amount_minor' => 10000000,
            'min_term' => 1, 'max_term' => 24, 'fee_type' => 'percent', 'fee_value' => '2', 'fee_collection' => 'deducted',
            'penalty_type' => 'percent', 'penalty_value' => '10', 'grace_period_days' => 0, 'max_active_loans' => 1,
            'is_active' => true,
        ]));
    }

    /**
     * An approved loan (maker = officer, checker = admin), optionally disbursed.
     */
    protected function loan(Company $company, Customer $customer, LoanProduct $product, User $maker, User $checker, int $amount = 100000, int $term = 4, ?string $disburseOn = null): Loan
    {
        $service = app(LoanService::class);

        $application = $this->inTenant($company, fn () => $service->createApplication([
            'customer_id' => $customer->id, 'loan_product_id' => $product->id, 'amount_requested_minor' => $amount,
            'term' => $term, 'purpose' => 'Working capital', 'monthly_obligations_minor' => 0,
        ]), $maker);
        $this->inTenant($company, fn () => $service->submit($application), $maker);
        $loan = $this->inTenant($company, fn () => $service->approve($application, null, null, 'ok'), $checker);

        if ($disburseOn !== null) {
            $loan = $this->inTenant($company, fn () => $service->disburse($loan, $disburseOn, 'cash', null, true), $checker);
        }

        return $loan;
    }
}
