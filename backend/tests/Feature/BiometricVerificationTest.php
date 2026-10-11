<?php

namespace Tests\Feature;

use App\Models\BiometricVerification;
use App\Models\Company;
use App\Services\Biometrics\Providers\FingerprintBridgeProvider;
use App\Services\Biometrics\Providers\SimulatedProvider;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Biometrics are an onboarding identity check only. These tests pin down that
 * nothing reports a match without a provider, that consent and policy are
 * enforced server-side, and that results are authenticated.
 */
class BiometricVerificationTest extends TestCase
{
    /** @return array{0: \App\Models\Customer, 1: \App\Models\User, 2: Company} */
    private function onboardingCustomer(bool $withBiometricConsent = true): array
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $company->settings = ['onboarding' => ['individual_requires_face' => true]];
        $company->save();

        $customer = $this->individualCustomer($company, $branch, onboarded: false);
        $this->actingAsUser($admin);
        $this->postJson("/api/customers/{$customer->id}/consents", ['consent_type' => 'data_processing', 'granted' => true, 'capture_method' => 'signed_form']);
        if ($withBiometricConsent) {
            $this->postJson("/api/customers/{$customer->id}/consents", ['consent_type' => 'biometric_processing', 'granted' => true, 'capture_method' => 'signed_form']);
        }

        return [$customer, $admin, $company];
    }

    #[Test]
    public function with_no_provider_configured_nothing_can_be_verified(): void
    {
        config(['biometrics.face.driver' => 'unconfigured']);
        [$customer] = $this->onboardingCustomer();

        $this->getJson('/api/biometrics/providers')->assertOk()->assertJsonPath('providers.face.configured', false);
        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'provider_not_configured');

        $this->assertSame(0, BiometricVerification::query()->withoutGlobalScopes()->where('status', 'verified')->count());
        $this->getJson("/api/customers/{$customer->id}/onboarding")->assertJsonPath('ready', false);
    }

    #[Test]
    public function biometric_capture_requires_recorded_consent(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        [$customer] = $this->onboardingCustomer(withBiometricConsent: false);

        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'biometric_consent_required');
    }

    #[Test]
    public function a_simulated_match_is_recorded_and_labelled_as_simulated(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        [$customer] = $this->onboardingCustomer();

        $start = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->assertCreated();
        $start->assertJsonPath('attempt.status', 'pending')->assertJsonPath('attempt.is_simulated', true)->assertJsonPath('client.mode', 'simulated');
        $attemptId = $start->json('attempt.id');

        $this->postJson("/api/customers/{$customer->id}/biometrics/{$attemptId}/complete", ['simulate_outcome' => 'verified'])
            ->assertOk()->assertJsonPath('attempt.status', 'verified')
            ->assertJsonMissingPath('attempt.provider_reference')
            ->assertJsonMissingPath('attempt.metadata');

        $item = collect($this->getJson("/api/customers/{$customer->id}/onboarding")->json('items'))->firstWhere('key', 'face_verification');
        $this->assertSame('complete', $item['status']);
        $this->assertStringContainsString('SIMULATED', $item['detail']);

        $attempt = BiometricVerification::query()->withoutGlobalScopes()->find($attemptId);
        $this->assertNotNull($attempt->operator_id);
        $this->assertNotNull($attempt->consent_record_id);
        $this->assertNotNull($attempt->retain_until);
        $this->assertStringNotContainsString('SIM-', (string) \DB::table('biometric_verifications')->where('id', $attemptId)->value('provider_reference'),
            'provider reference must be encrypted at rest');
    }

    #[Test]
    public function failed_outcomes_are_recorded_with_a_reason_and_do_not_satisfy_onboarding(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        [$customer] = $this->onboardingCustomer();

        foreach (['no_match' => 'no_match', 'liveness_failed' => 'liveness_failed', 'poor_quality' => 'poor_quality'] as $outcome => $reason) {
            $id = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->json('attempt.id');
            $this->postJson("/api/customers/{$customer->id}/biometrics/{$id}/complete", ['simulate_outcome' => $outcome])
                ->assertOk()->assertJsonPath('attempt.status', 'failed')->assertJsonPath('attempt.failure_reason', $reason);
        }

        $this->getJson("/api/customers/{$customer->id}")->assertJsonPath('customer.verification_status', 'failed');
        $this->getJson("/api/customers/{$customer->id}/onboarding")->assertJsonPath('ready', false);
    }

    #[Test]
    public function provider_outages_and_browser_failures_are_handled(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        [$customer] = $this->onboardingCustomer();

        $id = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->json('attempt.id');
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$id}/complete", ['simulate_outcome' => 'provider_unavailable'])
            ->assertOk()->assertJsonPath('attempt.status', 'failed')->assertJsonPath('attempt.failure_reason', 'provider_unavailable');

        $id = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->json('attempt.id');
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$id}/client-failure", ['reason' => 'camera_permission_denied'])
            ->assertOk()->assertJsonPath('attempt.status', 'failed')->assertJsonPath('attempt.failure_reason', 'camera_permission_denied');

        // A closed attempt cannot be completed afterwards.
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$id}/complete", ['simulate_outcome' => 'verified'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'attempt_closed');
    }

    #[Test]
    public function starting_a_new_attempt_cancels_the_interrupted_one_and_daily_limits_apply(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        [$customer] = $this->onboardingCustomer();

        $first = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->json('attempt.id');
        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->assertCreated();
        $this->assertSame('cancelled', BiometricVerification::query()->withoutGlobalScopes()->find($first)->status);

        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'attempt_limit_reached');
    }

    #[Test]
    public function a_business_itself_cannot_be_biometrically_verified(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        ['branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $this->actingAsUser($admin);

        $response = $this->postJson('/api/customers', [
            'type' => 'business', 'branch_id' => $branch->id, 'business_name' => 'Volta Agro Ltd', 'registration_number' => 'CS9',
            'business_type' => 'limited_company', 'phone' => '0302000000',
            'representatives' => [['full_name' => 'Efua Sam', 'id_type' => 'ghana_card', 'id_number' => 'GHA-9', 'is_signatory' => true]],
        ]);
        $id = $response->json('customer.id');
        $repId = $response->json('customer.representatives.0.id');

        $this->postJson("/api/customers/{$id}/biometrics", ['modality' => 'face'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'representative_required');

        $this->postJson("/api/customers/{$id}/consents", ['consent_type' => 'biometric_processing', 'granted' => true, 'capture_method' => 'signed_form', 'representative_id' => $repId]);
        $attempt = $this->postJson("/api/customers/{$id}/biometrics", ['modality' => 'face', 'representative_id' => $repId])->assertCreated();
        $this->assertSame($repId, $attempt->json('attempt.representative_id'));
    }

    #[Test]
    public function biometrics_are_unavailable_once_onboarding_is_closed(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch, onboarded: true);
        $this->actingAsUser($admin);

        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'onboarding_closed');
    }

    #[Test]
    public function the_simulator_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);
        new SimulatedProvider('face');
    }

    #[Test]
    public function fingerprint_bridge_results_must_be_authentic_fresh_and_bound_to_the_attempt(): void
    {
        config(['biometrics.fingerprint.driver' => 'bridge', 'biometrics.fingerprint.bridge.secret' => 'bridge-secret']);
        [$customer, $admin, $company] = $this->onboardingCustomer();
        $company->settings = ['onboarding' => ['individual_requires_face' => false, 'individual_requires_fingerprint' => true]];
        $company->save();

        $sign = fn (array $result) => FingerprintBridgeProvider::sign($result, 'bridge-secret');

        // 1. Forged signature.
        $start = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'fingerprint'])->assertCreated();
        $start->assertJsonPath('client.mode', 'bridge');
        $result = ['attempt_id' => $start->json('attempt.id'), 'nonce' => $start->json('client.nonce'), 'outcome' => 'match', 'method' => 'match_on_card', 'card_pin' => $customer->id_number,
            'match_score' => 0.95, 'provider_reference' => 'FP-1', 'issued_at' => now()->toIso8601String()];
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$result['attempt_id']}/complete", ['result' => $result, 'signature' => $sign($result + ['x' => 1])])
            ->assertOk()->assertJsonPath('attempt.status', 'failed')->assertJsonPath('attempt.failure_reason', 'invalid_signature');
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.biometric_signature_rejected']);

        // 2. Valid signature but replayed against a different attempt (nonce mismatch).
        $start2 = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'fingerprint'])->assertCreated();
        $replay = ['attempt_id' => $start2->json('attempt.id')] + $result;
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$replay['attempt_id']}/complete", ['result' => $replay, 'signature' => $sign($replay)])
            ->assertOk()->assertJsonPath('attempt.failure_reason', 'invalid_signature');

        // 3. Genuine result.
        $start3 = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'fingerprint'])->assertCreated();
        $good = ['attempt_id' => $start3->json('attempt.id'), 'nonce' => $start3->json('client.nonce'), 'outcome' => 'match', 'method' => 'match_on_card', 'card_pin' => $customer->id_number,
            'match_score' => 0.93, 'provider_reference' => 'FP-3', 'issued_at' => now()->toIso8601String(), 'device_model' => 'Test Scanner'];
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$good['attempt_id']}/complete", ['result' => $good, 'signature' => $sign($good)])
            ->assertOk()->assertJsonPath('attempt.status', 'verified')->assertJsonPath('attempt.is_simulated', false);

        $item = collect($this->getJson("/api/customers/{$customer->id}/onboarding")->json('items'))->firstWhere('key', 'fingerprint_verification');
        $this->assertSame('complete', $item['status']);
    }

    #[Test]
    public function stale_or_low_scoring_bridge_results_are_not_accepted_as_matches(): void
    {
        config(['biometrics.fingerprint.driver' => 'bridge', 'biometrics.fingerprint.bridge.secret' => 's', 'biometrics.fingerprint.bridge.min_match_score' => 0.8]);
        $company = null;
        [$customer] = $this->onboardingCustomer();

        $s = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'fingerprint']);
        $stale = ['attempt_id' => $s->json('attempt.id'), 'nonce' => $s->json('client.nonce'), 'outcome' => 'match', 'method' => 'match_on_card', 'card_pin' => $customer->id_number, 'match_score' => 0.99,
            'issued_at' => now()->subHour()->toIso8601String()];
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$stale['attempt_id']}/complete", ['result' => $stale, 'signature' => FingerprintBridgeProvider::sign($stale, 's')])
            ->assertJsonPath('attempt.failure_reason', 'expired');

        $s = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'fingerprint']);
        $low = ['attempt_id' => $s->json('attempt.id'), 'nonce' => $s->json('client.nonce'), 'outcome' => 'match', 'method' => 'match_on_card', 'card_pin' => $customer->id_number, 'match_score' => 0.55,
            'issued_at' => now()->toIso8601String()];
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$low['attempt_id']}/complete", ['result' => $low, 'signature' => FingerprintBridgeProvider::sign($low, 's')])
            ->assertJsonPath('attempt.status', 'manual_review');
    }

    #[Test]
    public function retention_purge_clears_provider_references_but_keeps_the_outcome(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        [$customer] = $this->onboardingCustomer();
        $id = $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->json('attempt.id');
        $this->postJson("/api/customers/{$customer->id}/biometrics/{$id}/complete", ['simulate_outcome' => 'verified']);

        \DB::table('biometric_verifications')->where('id', $id)->update(['retain_until' => now()->subDay()->toDateString()]);
        Artisan::call('biometrics:purge-expired');

        $row = \DB::table('biometric_verifications')->where('id', $id)->first();
        $this->assertNull($row->provider_reference);
        $this->assertNull($row->metadata);
        $this->assertNotNull($row->purged_at);
        $this->assertSame('verified', $row->status);
    }

    #[Test]
    public function biometric_endpoints_require_the_capture_permission(): void
    {
        config(['biometrics.face.driver' => 'simulated', 'biometrics.allow_simulated' => true]);
        ['company' => $company, 'branch' => $branch] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch, onboarded: false);
        $this->actingAsUser($this->staff($company, 'cashier', $branch));

        $this->postJson("/api/customers/{$customer->id}/biometrics", ['modality' => 'face'])->assertForbidden();
    }
}
