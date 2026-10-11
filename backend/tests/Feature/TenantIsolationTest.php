<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\Tenancy\MissingTenantException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    #[Test]
    public function a_company_never_sees_another_companys_customers(): void
    {
        $a = $this->newCompany('Alpha');
        $b = $this->newCompany('Beta');
        $alphaCustomer = $this->individualCustomer($a['company'], $a['branch']);
        $this->individualCustomer($b['company'], $b['branch']);

        $this->actingAsUser($b['admin']);

        $list = $this->getJson('/api/customers')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertNotSame($alphaCustomer->id, $list[0]['id']);

        // Direct object reference to another company's record is a plain 404.
        $this->getJson("/api/customers/{$alphaCustomer->id}")->assertNotFound()->assertJsonPath('error_code', 'not_found');
        $this->putJson("/api/customers/{$alphaCustomer->id}", ['phone' => '0200000000'])->assertNotFound();
        $this->getJson("/api/customers/{$alphaCustomer->id}/documents")->assertNotFound();
    }

    #[Test]
    public function another_companys_ids_cannot_be_smuggled_into_new_records(): void
    {
        $a = $this->newCompany('Alpha');
        $b = $this->newCompany('Beta');
        $alphaCustomer = $this->individualCustomer($a['company'], $a['branch']);
        $betaProduct = $this->product($b['company']);

        $this->actingAsUser($b['admin']);

        $this->postJson('/api/loan-applications', [
            'customer_id' => $alphaCustomer->id, 'loan_product_id' => $betaProduct->id,
            'amount' => 500, 'term' => 3, 'purpose' => 'test',
        ])->assertNotFound();

        $this->postJson('/api/customers', [
            'type' => 'individual', 'branch_id' => $a['branch']->id, 'first_name' => 'X', 'last_name' => 'Y',
            'date_of_birth' => '1990-01-01', 'phone' => '0201234567', 'id_type' => 'ghana_card', 'id_number' => 'GHA-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    #[Test]
    public function company_id_in_the_payload_is_ignored(): void
    {
        $a = $this->newCompany('Alpha');
        $b = $this->newCompany('Beta');
        $this->actingAsUser($b['admin']);

        $id = $this->postJson('/api/services', [
            'name' => 'Advice', 'code' => 'ADV', 'price' => 10, 'company_id' => $a['company']->id,
        ])->assertCreated()->json('id');

        $this->assertSame($b['company']->id, \DB::table('services')->where('id', $id)->value('company_id'));
    }

    #[Test]
    public function queries_without_a_company_context_fail_closed(): void
    {
        $this->newCompany();

        $this->expectException(MissingTenantException::class);
        Customer::query()->count();
    }

    #[Test]
    public function branch_restricted_staff_only_see_their_branches(): void
    {
        ['company' => $company, 'branch' => $accra] = $this->newCompany();
        $kumasi = $this->branch($company, 'Kumasi');
        $accraCustomer = $this->individualCustomer($company, $accra);
        $kumasiCustomer = $this->individualCustomer($company, $kumasi);

        $kumasiOfficer = $this->staff($company, 'loan-officer', $kumasi);
        $this->actingAsUser($kumasiOfficer);

        $ids = collect($this->getJson('/api/customers')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($kumasiCustomer->id));
        $this->assertFalse($ids->contains($accraCustomer->id));
        $this->getJson("/api/customers/{$accraCustomer->id}")->assertNotFound();

        // ... and cannot register a customer into a branch they are not assigned to.
        $this->postJson('/api/customers', [
            'type' => 'individual', 'branch_id' => $accra->id, 'first_name' => 'X', 'last_name' => 'Y',
            'date_of_birth' => '1990-01-01', 'phone' => '0201234567', 'id_type' => 'ghana_card', 'id_number' => 'GHA-77',
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    #[Test]
    public function staff_with_all_branch_access_see_every_branch(): void
    {
        ['company' => $company, 'branch' => $accra] = $this->newCompany();
        $kumasi = $this->branch($company, 'Kumasi');
        $this->individualCustomer($company, $accra);
        $this->individualCustomer($company, $kumasi);

        $this->actingAsUser($this->staff($company, 'compliance-officer', $accra));

        $this->assertCount(2, $this->getJson('/api/customers')->assertOk()->json('data'));
    }
}
