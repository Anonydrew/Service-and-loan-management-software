<?php

namespace Tests\Unit;

use App\Models\LoanInstallment;
use App\Services\Loans\RepaymentAllocator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RepaymentAllocatorTest extends TestCase
{
    private function installment(int $id, int $principal, int $interest, int $fees = 0, int $penalty = 0, array $paid = []): LoanInstallment
    {
        $i = new LoanInstallment;
        $i->forceFill([
            'id' => $id,
            'installment_number' => $id,
            'principal_due_minor' => $principal, 'interest_due_minor' => $interest,
            'fees_due_minor' => $fees, 'penalty_due_minor' => $penalty,
            'principal_paid_minor' => $paid['principal'] ?? 0, 'interest_paid_minor' => $paid['interest'] ?? 0,
            'fees_paid_minor' => $paid['fees'] ?? 0, 'penalty_paid_minor' => $paid['penalty'] ?? 0,
        ]);

        return $i;
    }

    #[Test]
    public function partial_payment_clears_penalty_then_fees_then_interest_before_principal(): void
    {
        $plan = (new RepaymentAllocator)->allocate([$this->installment(1, 10000, 2000, 500, 300)], 2600);

        $a = $plan['allocations'][0];
        $this->assertSame([300, 500, 1800, 0], [$a['penalty'], $a['fees'], $a['interest'], $a['principal']]);
        $this->assertSame(0, $plan['unallocated']);
    }

    #[Test]
    public function oldest_installment_is_settled_before_the_next(): void
    {
        $plan = (new RepaymentAllocator)->allocate([
            $this->installment(1, 10000, 1000),
            $this->installment(2, 10000, 1000),
        ], 13000);

        $this->assertSame(10000, $plan['allocations'][0]['principal']);
        $this->assertSame(1000, $plan['allocations'][0]['interest']);
        $this->assertSame(1000, $plan['allocations'][1]['interest']);
        $this->assertSame(1000, $plan['allocations'][1]['principal']);
    }

    #[Test]
    public function previously_paid_components_are_respected(): void
    {
        $plan = (new RepaymentAllocator)->allocate([$this->installment(1, 10000, 1000, 0, 0, ['interest' => 1000, 'principal' => 4000])], 6000);

        $this->assertSame(0, $plan['allocations'][0]['interest']);
        $this->assertSame(6000, $plan['allocations'][0]['principal']);
    }

    #[Test]
    public function excess_over_everything_owed_is_reported_unallocated(): void
    {
        $plan = (new RepaymentAllocator)->allocate([$this->installment(1, 1000, 100)], 1500);

        $this->assertSame(400, $plan['unallocated']);
    }
}
