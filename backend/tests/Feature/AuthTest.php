<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    #[Test]
    public function staff_can_sign_in_and_receive_their_profile_and_permissions(): void
    {
        ['admin' => $admin] = $this->newCompany();

        $response = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Secret-Pass-123'])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertContains('customers.create', $response->json('user.permissions'));
        $this->assertTrue($response->json('user.all_branches'));

        $this->withToken($response->json('token'))->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', $admin->email);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login')->exists());
    }

    #[Test]
    public function login_attempts_are_rate_limited(): void
    {
        ['admin' => $admin] = $this->newCompany();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong'])->assertTooManyRequests();
    }

    #[Test]
    public function repeated_failures_lock_the_account(): void
    {
        // Exercise the account lockout on its own, independent of the IP rate limiter.
        $this->withoutMiddleware(ThrottleRequests::class);
        ['admin' => $admin] = $this->newCompany();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->assertNotNull($admin->fresh()->locked_until);
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Secret-Pass-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    #[Test]
    public function disabled_accounts_cannot_sign_in(): void
    {
        ['admin' => $admin] = $this->newCompany();
        $admin->forceFill(['status' => 'disabled'])->save();

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Secret-Pass-123'])->assertUnprocessable();
    }
}
