<?php

namespace Tests\Feature;

use App\Models\BiometricVerification;
use App\Models\Customer;
use App\Services\Biometrics\Providers\FingerprintBridgeProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * L.I. 2523 (National Identity Register (Amendment) Regulations, 2026):
 * Ghana Card holders are confirmed biometrically (match-on-card or NIA
 * register); card images may not be kept; visual inspection is not enough.
 */
class GhanaCardComplianceTest extends TestCase
{
    private const SECRET = 'bridge-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'biometrics.fingerprint.driver' => 'bridge',
            'biometrics.fingerprint.bridge.secret' => self::SECRET,
            'biometrics.fingerprint.bridge.mode' => 'match_on_card',
        ]);
    }

    /** @return array{0: Customer, 1: \App\Models\User} */
    private function ghanaCardHolder(array $overrides = []): array
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch, $overrides + ['id_number' => 'GHA-712345678-9'], onboarded: false);
        $this->actingAsUser($admin);
        foreach (['data_processing', 'biometric_processing'] as $type) {
            $this->postJson("/api/customers/{$customer->id}/consents", ['consent_type' => $type, 'granted' => true, 'capture_method' => 'signed_form'])->assertCreated();
        }

        return [$customer, $admin];
    }

    /** Simulates the workstation bridge returning a signed match-on-card result. */
    private function matchOnCard(Customer $customer, string $cardPin, string $outcome = 'match'): \Illuminate\Testing\TestResponse
    {
        $start = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'fingerprint'])->assertCreated()
            ->assertJsonPath('client.method', 'match_on_card')
            ->assertJsonPath('client.require_card', true)
            ->assertJsonMissingPath('client.subject.id_number');

        $result = [
            'attempt_id' => $start->json('attempt.id'), 'nonce' => $start->json('client.nonce'), 'method' => 'match_on_card',
            'outcome' => $outcome, 'match_score' => 0.96, 'card_pin' => $cardPin, 'provider_reference' => 'MOC-1',
            'issued_at' => now()->toIso8601String(), 'device_model' => 'DERMALOG ZF1+', 'liveness_passed' => true,
        ];

        return $this->postJson("/api/customers/{$customer->id}/biometrics/{$result['attempt_id']}/complete", [
            'result' => $result, 'signature' => FingerprintBridgeProvider::sign($result, self::SECRET),
        ]);
    }

    #[Test]
    public function ghana_card_images_cannot_be_uploaded(): void
    {
        Storage::fake('local');
        [$customer] = $this->ghanaCardHolder();

        $this->postJson("/api/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->image('card.jpg'), 'document_type' => 'ghana_card',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'ghana_card_image_prohibited');

        $this->assertSame(0, DB::table('customer_documents')->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertNotContains('ghana_card', $this->getJson('/api/lookups')->json('enums.document_types'));
    }

    #[Test]
    public function a_ghana_card_holder_needs_a_biometric_match_not_a_document(): void
    {
        [$customer] = $this->ghanaCardHolder();

        $items = collect($this->getJson("/api/customers/{$customer->id}/onboarding")->json('items'))->keyBy('key');
        $this->assertArrayNotHasKey('id_document', $items->all());
        $this->assertSame('incomplete', $items['ghana_card_biometric']['status']);
        $this->assertTrue($items['ghana_card_biometric']['required']);

        $this->postJson("/api/customers/{$customer->id}/onboarding/complete")->assertUnprocessable()->assertJsonPath('error_code', 'onboarding_incomplete');

        $this->matchOnCard($customer, 'gha 7123456789')->assertOk()->assertJsonPath('attempt.status', 'verified')
            ->assertJsonPath('attempt.verification_method', 'match_on_card');

        $this->getJson("/api/customers/{$customer->id}/onboarding")->assertJsonPath('ready', true);
        $this->postJson("/api/customers/{$customer->id}/onboarding/complete")->assertOk()->assertJsonPath('status', 'active');
    }

    #[Test]
    public function someone_elses_ghana_card_does_not_verify_the_customer(): void
    {
        [$customer] = $this->ghanaCardHolder();

        $this->matchOnCard($customer, 'GHA-700000000-1')->assertOk()
            ->assertJsonPath('attempt.status', 'failed')->assertJsonPath('attempt.failure_reason', 'card_mismatch');

        $this->getJson("/api/customers/{$customer->id}/onboarding")->assertJsonPath('ready', false);
    }

    #[Test]
    public function an_unreadable_chip_or_failed_liveness_is_a_failure(): void
    {
        [$customer] = $this->ghanaCardHolder();

        $this->matchOnCard($customer, 'GHA-712345678-9', 'card_unreadable')->assertJsonPath('attempt.failure_reason', 'card_unreadable');
    }

    #[Test]
    public function match_on_card_is_refused_for_people_without_a_ghana_card(): void
    {
        [$customer] = $this->ghanaCardHolder(['id_type' => 'passport', 'id_number' => 'G1112223']);

        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'fingerprint'])->assertCreated()
            ->assertJsonPath('attempt.status', 'failed')->assertJsonPath('attempt.failure_reason', 'card_required');
    }

    #[Test]
    public function manual_review_cannot_approve_a_ghana_card_holder_by_inspection(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $officer = $this->staff($company, 'loan-officer', $branch);
        $compliance = $this->staff($company, 'compliance-officer', $branch);
        $customer = $this->individualCustomer($company, $branch, onboarded: false, actor: $officer);

        $this->actingAsUser($officer);
        $this->postJson("/api/customers/{$customer->id}/consents", ['consent_type' => 'data_processing', 'granted' => true, 'capture_method' => 'signed_form']);
        $this->postJson("/api/customers/{$customer->id}/onboarding/refer", ['reason' => 'Scanner offline'])->assertOk();

        $this->actingAsUser($compliance);
        $this->postJson("/api/customers/{$customer->id}/onboarding/decide", ['decision' => 'approve', 'notes' => 'Looked at the card in person.'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'ghana_card_biometric_required');

        // Rejection remains possible.
        $this->postJson("/api/customers/{$customer->id}/onboarding/decide", ['decision' => 'reject', 'notes' => 'Customer unable to complete biometric check.'])
            ->assertOk()->assertJsonPath('status', 'rejected');
    }

    #[Test]
    public function every_ghana_card_signatory_of_a_business_must_be_verified(): void
    {
        ['branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $this->actingAsUser($admin);
        $response = $this->postJson('/api/customers', [
            'type' => 'business', 'branch_id' => $branch->id, 'business_name' => 'Asante Logistics Ltd', 'registration_number' => 'CS44',
            'business_type' => 'limited_company', 'phone' => '0302000444',
            'representatives' => [
                ['full_name' => 'Kofi Asante', 'id_type' => 'ghana_card', 'id_number' => 'GHA-711111111-1', 'is_signatory' => true],
                ['full_name' => 'Ama Asante', 'id_type' => 'ghana_card', 'id_number' => 'GHA-722222222-2', 'is_signatory' => true],
                ['full_name' => 'John Doe', 'id_type' => 'passport', 'id_number' => 'P999', 'is_signatory' => false],
            ],
        ])->assertCreated();

        $keys = collect($response->json('onboarding.items'))->pluck('key');
        $this->assertCount(2, $keys->filter(fn ($k) => str_starts_with($k, 'ghana_card_biometric_')));
    }

    #[Test]
    public function previously_stored_ghana_card_images_can_be_purged(): void
    {
        Storage::fake('local');
        [$customer] = $this->ghanaCardHolder();
        Storage::disk('local')->put('customer-documents/old-card.jpg', 'image-bytes');
        DB::table('customer_documents')->insert([
            'company_id' => $customer->company_id, 'customer_id' => $customer->id, 'document_type' => 'ghana_card',
            'file_path' => 'customer-documents/old-card.jpg', 'original_name' => 'card.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => 11, 'checksum' => str_repeat('a', 64), 'status' => 'verified', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('documents:purge-ghana-card-images', ['--dry-run' => true]);
        Storage::disk('local')->assertExists('customer-documents/old-card.jpg');

        Artisan::call('documents:purge-ghana-card-images');
        Storage::disk('local')->assertMissing('customer-documents/old-card.jpg');
        $this->assertSame(0, DB::table('customer_documents')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_document.purged']);
    }

    #[Test]
    public function the_verification_method_survives_retention_purge(): void
    {
        [$customer] = $this->ghanaCardHolder();
        $id = $this->matchOnCard($customer, 'GHA-712345678-9')->json('attempt.id');

        DB::table('biometric_verifications')->where('id', $id)->update(['retain_until' => now()->subDay()->toDateString()]);
        Artisan::call('biometrics:purge-expired');

        $row = BiometricVerification::query()->withoutGlobalScopes()->find($id);
        $this->assertNull($row->provider_reference);
        $this->assertSame('match_on_card', $row->verification_method);
    }
}
