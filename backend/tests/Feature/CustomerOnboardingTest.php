<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerOnboardingTest extends TestCase
{
    private function individualPayload(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'individual', 'first_name' => 'Kwame', 'last_name' => 'Nkansah', 'date_of_birth' => '1987-04-12',
            'phone' => '+233244123456', 'email' => 'kwame@example.test', 'id_type' => 'ghana_card', 'id_number' => 'GHA-123456789-0',
            'monthly_income' => '3500.50',
        ];
    }

    #[Test]
    public function an_individual_customer_is_registered_with_encrypted_identity_number(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $this->actingAsUser($admin);

        $response = $this->postJson('/api/customers', $this->individualPayload(['branch_id' => $branch->id]))->assertCreated();

        $response->assertJsonPath('customer.display_name', 'Kwame Nkansah')
            ->assertJsonPath('customer.status', 'draft')
            ->assertJsonPath('customer.onboarding_status', 'in_progress')
            ->assertJsonPath('customer.monthly_income_minor', 350050)
            ->assertJsonMissingPath('customer.id_number')
            ->assertJsonMissingPath('customer.id_number_hash')
            ->assertJsonPath('onboarding.ready', false);

        $masked = $response->json('customer.id_number_masked');
        $this->assertStringEndsWith('89-0', $masked);
        $this->assertStringNotContainsString('1234567', $masked);

        $raw = \DB::table('customers')->where('id', $response->json('customer.id'))->value('id_number');
        $this->assertStringNotContainsString('123456789', $raw, 'identity number must be encrypted at rest');
        $this->assertStringStartsWith('CUS-', $response->json('customer.customer_number'));
    }

    #[Test]
    public function duplicate_identity_numbers_are_rejected_across_branches(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $other = $this->branch($company);
        $this->individualCustomer($company, $other, ['id_number' => 'GHA 123456789 0']);
        $this->actingAsUser($admin);

        $this->postJson('/api/customers', $this->individualPayload(['branch_id' => $branch->id]))
            ->assertUnprocessable()->assertJsonPath('error_code', 'duplicate_identity');
    }

    #[Test]
    public function minors_cannot_be_registered(): void
    {
        ['branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $this->actingAsUser($admin);

        $this->postJson('/api/customers', $this->individualPayload(['branch_id' => $branch->id, 'date_of_birth' => now()->subYears(16)->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
    }

    #[Test]
    public function full_individual_onboarding_with_document_check_by_a_second_person(): void
    {
        Storage::fake('local');
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $compliance = $this->staff($company, 'compliance-officer', $branch);

        $this->actingAsUser($officer);
        $id = $this->postJson('/api/customers', $this->individualPayload(['branch_id' => $branch->id]))->json('customer.id');

        // Completing too early lists what is missing.
        $this->postJson("/api/customers/{$id}/onboarding/complete")
            ->assertUnprocessable()->assertJsonPath('error_code', 'onboarding_incomplete');

        $this->postJson("/api/customers/{$id}/consents", ['consent_type' => 'data_processing', 'granted' => true, 'capture_method' => 'signed_form'])->assertCreated();

        $docId = $this->postJson("/api/customers/{$id}/documents", [
            'file' => UploadedFile::fake()->image('ghana-card.jpg'), 'document_type' => 'ghana_card',
            'document_number' => 'GHA-123456789-0', 'expiry_date' => now()->addYears(5)->toDateString(),
        ])->assertCreated()->assertJsonMissingPath('file_path')->json('id');

        // The uploader is not allowed to verify their own upload (and lacks the permission anyway).
        $this->postJson("/api/customers/{$id}/documents/{$docId}/verify", ['decision' => 'verified'])->assertForbidden();

        $this->actingAsUser($compliance);
        $this->getJson("/api/customers/{$id}/documents/{$docId}/download")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson("/api/customers/{$id}/documents/{$docId}/verify", ['decision' => 'verified'])->assertOk()->assertJsonPath('status', 'verified');

        $this->actingAsUser($officer);
        $this->getJson("/api/customers/{$id}/onboarding")->assertOk()->assertJsonPath('ready', true);
        $this->postJson("/api/customers/{$id}/onboarding/complete")->assertOk()
            ->assertJsonPath('status', 'active')->assertJsonPath('onboarding_status', 'completed')->assertJsonPath('verification_status', 'verified');

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_document.downloaded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.onboarded']);
    }

    #[Test]
    public function document_four_eyes_rule_applies_even_to_a_verifier(): void
    {
        Storage::fake('local');
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch, onboarded: false);
        $this->actingAsUser($admin);

        $docId = $this->postJson("/api/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->create('card.pdf', 200, 'application/pdf'), 'document_type' => 'passport',
        ])->assertCreated()->json('id');

        $this->postJson("/api/customers/{$customer->id}/documents/{$docId}/verify", ['decision' => 'verified'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'four_eyes');
    }

    #[Test]
    public function uploads_are_restricted_to_images_and_pdfs(): void
    {
        Storage::fake('local');
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch, onboarded: false);
        $this->actingAsUser($admin);

        $this->postJson("/api/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'), 'document_type' => 'passport',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    #[Test]
    public function business_customers_require_a_representative_and_registration_documents(): void
    {
        Storage::fake('local');
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $this->actingAsUser($admin);

        $base = ['type' => 'business', 'branch_id' => $branch->id, 'business_name' => 'Adinkra Prints Ltd', 'registration_number' => 'CS123',
            'business_type' => 'limited_company', 'phone' => '0302111222'];

        $this->postJson('/api/customers', $base)->assertUnprocessable()->assertJsonValidationErrors('representatives');

        $response = $this->postJson('/api/customers', $base + ['representatives' => [
            ['full_name' => 'Kojo Mensah', 'position' => 'Director', 'id_type' => 'ghana_card', 'id_number' => 'GHA-555', 'is_signatory' => true],
        ]])->assertCreated();

        $keys = collect($response->json('onboarding.items'))->pluck('key');
        $this->assertTrue($keys->contains('registration_document'));
        $this->assertTrue($keys->contains(fn ($k) => str_starts_with($k, 'representative_id_')));
        $this->assertFalse($keys->contains('id_document'), 'a business is not asked for a personal ID of its own');
        $this->assertTrue($response->json('customer.representatives.0.is_primary'));
    }

    #[Test]
    public function manual_review_must_be_decided_by_someone_other_than_the_registrar(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $compliance = $this->staff($company, 'compliance-officer', $branch);
        $customer = $this->individualCustomer($company, $branch, onboarded: false, actor: $officer);

        $this->actingAsUser($officer);
        $this->postJson("/api/customers/{$customer->id}/onboarding/refer", ['reason' => 'Customer declined biometrics'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'consent_required');
        $this->postJson("/api/customers/{$customer->id}/consents", ['consent_type' => 'data_processing', 'granted' => true, 'capture_method' => 'electronic']);
        $this->postJson("/api/customers/{$customer->id}/onboarding/refer", ['reason' => 'Customer declined biometrics'])
            ->assertOk()->assertJsonPath('onboarding_status', 'manual_review');

        $this->actingAsUser($compliance);
        $this->assertCount(1, $this->getJson('/api/onboarding/review-queue')->assertOk()->json('data'));
        $this->postJson("/api/customers/{$customer->id}/onboarding/decide", ['decision' => 'approve', 'notes' => 'Original Ghana Card inspected in person.'])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('verification_status', 'verified');

        $this->assertSame($compliance->id, Customer::query()->withoutGlobalScopes()->find($customer->id)->reviewed_by);
        $this->assertTrue($officer->fresh()->notifications()->exists(), 'registrar is told the outcome');
    }
}
