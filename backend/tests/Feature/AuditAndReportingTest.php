<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditAndReportingTest extends TestCase
{
    #[Test]
    public function sensitive_changes_are_audited_without_leaking_identity_numbers(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $customer = $this->individualCustomer($company, $branch);
        $this->actingAsUser($admin);

        $this->putJson("/api/customers/{$customer->id}", ['id_number' => 'GHA-999999999-9'])->assertOk();

        $log = AuditLog::query()->where('action', 'customer.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('[redacted]', $log->changes['id_number']);
        $this->assertStringNotContainsString('999999999', json_encode($log->changes));

        $this->getJson('/api/audit-logs?subject=Customer')->assertOk()->assertJsonPath('data.0.action', 'customer.updated');
    }

    #[Test]
    public function audit_logs_are_append_only(): void
    {
        $this->newCompany();
        $log = AuditLog::query()->first();

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    #[Test]
    public function audit_logs_of_other_companies_are_not_visible(): void
    {
        $a = $this->newCompany('Alpha');
        $b = $this->newCompany('Beta');
        $this->individualCustomer($a['company'], $a['branch']);
        $this->actingAsUser($b['admin']);

        $companies = collect($this->getJson('/api/audit-logs?per_page=100')->assertOk()->json('data'))->pluck('company_id')->unique();
        $this->assertEquals([$b['company']->id], $companies->values()->all());
    }

    #[Test]
    public function dashboard_and_reports_respect_permissions(): void
    {
        ['company' => $company, 'branch' => $branch, 'admin' => $admin] = $this->newCompany();
        $this->actingAsUser($this->staff($company, 'service-officer', $branch));

        $dashboard = $this->getJson('/api/dashboard')->assertOk();
        $this->assertArrayHasKey('services', $dashboard->json());
        $this->assertArrayNotHasKey('loans', $dashboard->json(), 'no loan figures without loans.view');
        $this->getJson('/api/reports/portfolio')->assertForbidden();

        $this->actingAsUser($admin);
        foreach (['portfolio', 'arrears_aging', 'disbursements', 'collections', 'customers', 'verification', 'service_revenue', 'expenses', 'branch_performance'] as $report) {
            $this->getJson("/api/reports/{$report}")->assertOk()->assertJsonStructure(['title', 'columns', 'rows']);
        }
        $this->get('/api/reports/collections?format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
