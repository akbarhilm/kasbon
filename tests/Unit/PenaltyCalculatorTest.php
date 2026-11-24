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
        config([
            'pinjaman.timezone' => 'Asia/Jakarta',
            'pinjaman.penalty_period_months' => 1,
            'pinjaman.penalty_grace_days' => 3,
        ]);
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

    public function test_no_penalty_during_grace_days_and_penalty_on_the_fourth_day(): void
    {
        // Jatuh tempo tgl 17: bebas denda sampai tgl 20, denda mulai tgl 21.
        $this->assertSame(0, $this->periods('2026-11-17', '2026-11-18 09:00'));
        $this->assertSame(0, $this->periods('2026-11-17', '2026-11-20 23:59'));
        $this->assertSame(1, $this->periods('2026-11-17', '2026-11-21 00:01'));
    }

    public function test_penalty_start_date_is_due_date_plus_grace_plus_one(): void
    {
        $start = PenaltyCalculator::penaltyStartsOn(CarbonImmutable::parse('2026-11-17', 'Asia/Jakarta'));

        $this->assertSame('2026-11-21', $start->toDateString());
    }

    public function test_second_period_follows_the_next_due_date_plus_grace(): void
    {
        // Jatuh tempo 1 Sep: periode 1 mulai 5 Sep, periode 2 mulai 5 Okt, periode 3 mulai 5 Nov.
        $this->assertSame(1, $this->periods('2026-09-01', '2026-09-10 10:00'));
        $this->assertSame(1, $this->periods('2026-09-01', '2026-10-04 23:00'));
        $this->assertSame(2, $this->periods('2026-09-01', '2026-10-05 00:10'));
        $this->assertSame(2, $this->periods('2026-09-01', '2026-10-11 10:00'));
        $this->assertSame(3, $this->periods('2026-09-01', '2026-11-05 10:00'));
    }

    public function test_grace_is_applied_to_each_period_after_month_end_rollover(): void
    {
        // Jatuh tempo 30 Agu: periode 2 jatuh tempo 30 Sep, ditambah 3 hari = batas 3 Okt.
        $this->assertSame(1, $this->periods('2026-08-30', '2026-10-03 12:00'));
        $this->assertSame(2, $this->periods('2026-08-30', '2026-10-04 12:00'));
    }

    public function test_zero_grace_keeps_the_original_behaviour(): void
    {
        config(['pinjaman.penalty_grace_days' => 0]);

        $this->assertSame(0, $this->periods('2026-10-12', '2026-10-12 23:59'));
        $this->assertSame(1, $this->periods('2026-10-12', '2026-10-13 00:01'));
        $this->assertSame(2, $this->periods('2026-09-01', '2026-10-02 00:10'));
    }

    public function test_penalty_amount_is_periods_times_admin_fee(): void
    {
        $this->assertSame(0, PenaltyCalculator::amount(500000, 0));
        $this->assertSame(500000, PenaltyCalculator::amount(500000, 1));
        $this->assertSame(1000000, PenaltyCalculator::amount(500000, 2));
    }
}
