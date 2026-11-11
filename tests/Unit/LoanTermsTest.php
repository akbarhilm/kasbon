<?php

namespace Tests\Unit;

use App\Services\LoanTerms;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LoanTermsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pinjaman.timezone' => 'Asia/Jakarta']);
    }

    public function test_due_date_is_disbursement_plus_tenor(): void
    {
        $due = LoanTerms::dueDate(CarbonImmutable::parse('2026-06-01 10:30', 'Asia/Jakarta'), 3);

        $this->assertSame('2026-09-01', $due->toDateString());
    }

    public function test_month_end_does_not_overflow(): void
    {
        $due = LoanTerms::dueDate(CarbonImmutable::parse('2026-01-31 09:00', 'Asia/Jakarta'), 1);

        $this->assertSame('2026-02-28', $due->toDateString());
    }

    public function test_admin_fee_default_ten_percent(): void
    {
        $this->assertSame(500000, LoanTerms::adminFee(5000000, 10));
    }

    public function test_admin_fee_is_rounded_to_whole_rupiah(): void
    {
        $this->assertSame(123457, LoanTerms::adminFee(1234567, 10));
    }
}
