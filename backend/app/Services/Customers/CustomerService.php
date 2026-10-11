<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerRepresentative;
use App\Support\BusinessRuleException;
use App\Support\IdentityHash;
use App\Support\Sequence;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

final class CustomerService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $representatives
     */
    public function create(array $data, array $representatives = []): Customer
    {
        $this->assertNotDuplicate($data['id_number'] ?? null, null);

        return DB::transaction(function () use ($data, $representatives): Customer {
            $customer = new Customer($data);
            $customer->forceFill([
                'customer_number' => Sequence::next('customer'),
                'status' => 'draft',
                'onboarding_status' => 'in_progress',
                'verification_status' => 'not_started',
                'created_by' => Tenant::user()?->id,
            ])->save();

            foreach ($representatives as $i => $rep) {
                $this->addRepresentative($customer, $rep + ['is_primary' => $i === 0]);
            }

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Customer $customer, array $data): Customer
    {
        if (array_key_exists('id_number', $data)) {
            $this->assertNotDuplicate($data['id_number'], $customer->id);
        }

        // Customer type is fixed at registration: it decides what is verified.
        unset($data['type']);

        $customer->fill($data)->save();

        return $customer;
    }

    /** @param array<string, mixed> $data */
    public function addRepresentative(Customer $customer, array $data): CustomerRepresentative
    {
        if (! $customer->isBusiness()) {
            throw new BusinessRuleException('Representatives can only be added to business customers.', 'not_business');
        }

        return DB::transaction(function () use ($customer, $data): CustomerRepresentative {
            if (! empty($data['is_primary'])) {
                $customer->representatives()->update(['is_primary' => false]);
            }

            $rep = new CustomerRepresentative($data);
            $rep->customer()->associate($customer);
            $rep->save();

            return $rep;
        });
    }

    /** One identity number may belong to only one customer in a company. */
    private function assertNotDuplicate(?string $idNumber, ?int $ignoreId): void
    {
        $hash = IdentityHash::make($idNumber);
        if ($hash === null) {
            return;
        }

        // Duplicates are checked across the whole company, not just the
        // user's branches, so the same person cannot be registered twice.
        $existing = Tenant::withoutScopes(fn () => Customer::query()
            ->where('company_id', Tenant::id())
            ->where('id_number_hash', $hash)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first(['id', 'customer_number', 'branch_id']));

        if ($existing !== null) {
            throw new BusinessRuleException(
                "A customer with this identity number already exists ({$existing->customer_number}).",
                'duplicate_identity',
                ['customer_number' => $existing->customer_number],
            );
        }
    }
}
