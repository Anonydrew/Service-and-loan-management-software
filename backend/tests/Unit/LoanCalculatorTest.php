<?php

namespace Tests\Unit;

use App\Services\Loans\LoanCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LoanCalculatorTest extends TestCase
{
    private LoanCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new LoanCalculator;
    }

    #[Test]
    public function flat_interest_is_charged_on_original_principal_every_period(): void
    {
        // GHS 1,000 at 5% per month flat over 4 months = GHS 200 interest.
        $s = $this->calc->schedule(100000, '5', 'per_month', 'flat', 4, 'monthly', CarbonImmutable::parse('2026-01-15'));

        $this->assertSame(20000, $s['total_interest']);
        $this->assertSame(120000, $s['total_payable']);
        $this->assertCount(4, $s['installments']);
        $this->assertSame(['principal' => 25000, 'interest' => 5000], array_intersect_key($s['installments'][0], ['principal' => 0, 'interest' => 0]));
        $this->assertSame('2026-02-15', $s['first_due_date']);
        $this->assertSame('2026-05-15', $s['maturity_date']);
    }

    #[Test]
    public function rounding_remainders_land_on_the_last_installment_so_totals_are_exact(): void
    {
        $s = $this->calc->schedule(100000, '5', 'per_month', 'flat', 3, 'monthly', CarbonImmutable::parse('2026-01-01'));

        $this->assertSame([33333, 33333, 33334], array_column($s['installments'], 'principal'));
        $this->assertSame(100000, array_sum(array_column($s['installments'], 'principal')));
        $this->assertSame($s['total_interest'], array_sum(array_column($s['installments'], 'interest')));
    }

    #[Test]
    public function reducing_balance_produces_equal_installments_and_amortises_fully(): void
    {
        // GHS 10,000 at 24% p.a. (2%/month) over 12 months: payment = 945.60.
        $s = $this->calc->schedule(1000000, '24', 'per_annum', 'reducing_balance', 12, 'monthly', CarbonImmutable::parse('2026-01-31'));

        $totals = array_column($s['installments'], 'total');
        $this->assertSame(94560, $totals[0]);
        foreach (array_slice($totals, 0, 11) as $t) {
            $this->assertSame(94560, $t);
        }
        $this->assertEqualsWithDelta(94560, $totals[11], 5, 'final installment absorbs rounding only');
        $this->assertSame(1000000, array_sum(array_column($s['installments'], 'principal')));
        $this->assertSame(20000, $s['installments'][0]['interest'], 'first month interest = 2% of 10,000');
        $this->assertSame('2026-02-28', $s['first_due_date'], 'month-end start does not overflow into March');
    }

    #[Test]
    public function zero_rate_reducing_balance_is_plain_division(): void
    {
        $s = $this->calc->schedule(90000, '0', 'per_annum', 'reducing_balance', 3, 'monthly', CarbonImmutable::parse('2026-01-01'));

        $this->assertSame(0, $s['total_interest']);
        $this->assertSame([30000, 30000, 30000], array_column($s['installments'], 'principal'));
    }

    #[Test]
    public function weekly_frequency_converts_a_monthly_rate_and_steps_weekly(): void
    {
        $s = $this->calc->schedule(52000, '4.5', 'per_month', 'flat', 4, 'weekly', CarbonImmutable::parse('2026-03-02'));

        // 4.5%/month = 54%/year = 1.03846...%/week; 520.00 * 1.0385% * 4 = 21.60
        $this->assertSame(2160, $s['total_interest']);
        $this->assertSame(['2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'], array_column($s['installments'], 'due_date'));
    }

    #[Test]
    public function capitalised_fees_are_spread_across_installments(): void
    {
        $s = $this->calc->schedule(100000, '0', 'per_month', 'flat', 4, 'monthly', CarbonImmutable::parse('2026-01-01'), 1001);

        $this->assertSame([250, 250, 250, 251], array_column($s['installments'], 'fees'));
        $this->assertSame(101001, $s['total_payable']);
    }

    #[Test]
    public function fees_are_percent_or_fixed(): void
    {
        $this->assertSame(2500, $this->calc->fee(100000, 'percent', '2.5'));
        $this->assertSame(5000, $this->calc->fee(100000, 'fixed', '50'));
    }

    #[Test]
    public function money_conversion_never_suffers_float_error(): void
    {
        $this->assertSame(29, Money::toMinor('0.29'));
        $this->assertSame(1999, Money::toMinor(19.99));
        $this->assertSame(1000001, Money::toMinor('10000.005'));
        $this->assertSame(333, Money::percentOf(10000, '3.33'));
        $this->assertSame('GHS 1,234.56', Money::format(123456));
    }
}
