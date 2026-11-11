<?php

namespace Tests\Unit;

use App\Services\PenaltyCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PenaltyCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pinjaman.timezone' => 'Asia/Jakarta', 'pinjaman.penalty_period_months' => 1]);
    }

    private function periods(string $due, string $paid): int
    {
        return PenaltyCalculator::periods(
            CarbonImmutable::parse($due, 'Asia/Jakarta'),
            CarbonImmutable::parse($paid, 'Asia/Jakarta'),
        );
    }

    public function test_no_penalty_on_or_before_due_date(): void
    {
        $this->assertSame(0, $this->periods('2026-10-12', '2026-10-01 08:00'));
        $this->assertSame(0, $this->periods('2026-10-12', '2026-10-12 23:59'));
    }

    public function test_one_day_late_is_one_period(): void
    {
        $this->assertSame(1, $this->periods('2026-10-12', '2026-10-13 00:01'));
        $this->assertSame(1, $this->periods('2026-10-12', '2026-10-21 12:00')); // 9 hari
    }

    public function test_second_period_starts_after_second_due_date(): void
    {
        $this->assertSame(1, $this->periods('2026-09-01', '2026-10-01 23:00'));
        $this->assertSame(2, $this->periods('2026-09-01', '2026-10-02 00:10'));
        $this->assertSame(2, $this->periods('2026-09-01', '2026-10-11 10:00')); // 40 hari
        $this->assertSame(3, $this->periods('2026-09-01', '2026-11-02 10:00'));
    }

    public function test_penalty_amount_is_periods_times_admin_fee(): void
    {
        $this->assertSame(0, PenaltyCalculator::amount(500000, 0));
        $this->assertSame(500000, PenaltyCalculator::amount(500000, 1));
        $this->assertSame(1000000, PenaltyCalculator::amount(500000, 2));
    }
}
