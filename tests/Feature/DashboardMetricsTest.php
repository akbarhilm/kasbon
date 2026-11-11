<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\LedgerService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Jakarta');

        config([
            'pinjaman.timezone' => 'Asia/Jakarta',
            'pinjaman.max_tenor_months' => 3,
            'pinjaman.penalty_period_months' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);

        parent::tearDown();
    }

    private function at(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'Asia/Jakarta');
    }

    /** @return array{0:Branch,1:User} */
    private function branchWithUser(string $kode, int $saldoAwal): array
    {
        $branch = Branch::create(['kode' => $kode, 'nama' => 'Cabang ' . $kode]);

        $user = (new User)->forceFill([
            'name' => 'Admin ' . $kode,
            'email' => strtolower($kode) . '@example.test',
            'password' => 'rahasia-123',
            'role' => Role::Cabang,
            'branch_id' => $branch->id,
        ]);
        $user->save();

        app(LedgerService::class)->post(
            $branch->id, LedgerPos::SaldoAwal, Arah::Masuk, $saldoAwal,
            $this->at('2026-06-01 08:00'), keterangan: 'Saldo awal', userId: $user->id,
        );

        return [$branch, $user];
    }

    private function borrower(Branch $branch, string $nama): Borrower
    {
        return Borrower::create(['branch_id' => $branch->id, 'nama' => $nama, 'no_hp' => '08' . random_int(100000, 999999)]);
    }

    public function test_metrics_for_active_loans_and_overdue(): void
    {
        [$branch, $user] = $this->branchWithUser('B01', 10_000_000);
        $loans = app(LoanService::class);
        $metrics = app(DashboardMetrics::class);

        $a = $loans->disburse($this->borrower($branch, 'Budi'), 5_000_000, 10, 3, $this->at('2026-06-01 10:00'), $user);
        $loans->disburse($this->borrower($branch, 'Siti'), 3_000_000, 10, 3, $this->at('2026-06-01 11:00'), $user);

        app(LedgerService::class)->post(
            $branch->id, LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000,
            $this->at('2026-06-15 09:00'), keterangan: 'Modal', userId: $user->id,
        );

        // saldo: 10.000.000 - 4.500.000 - 2.700.000 + 2.000.000
        $this->assertSame(4_800_000, $metrics->saldo($branch->id));
        $this->assertSame(8_000_000, $metrics->bakiPinjaman($branch->id));
        $this->assertSame(2, $metrics->jumlahPinjamanAktif($branch->id));

        // Jatuh tempo kedua pinjaman: 1 Sep 2026
        $this->assertSame(['jumlah' => 0, 'count' => 0], $metrics->bakiMacet($branch->id, $this->at('2026-09-01 12:00')));
        $this->assertSame(['jumlah' => 8_000_000, 'count' => 2], $metrics->bakiMacet($branch->id, $this->at('2026-09-02 08:00')));

        $this->assertSame(800_000, $metrics->pendapatanAdministrasi($branch->id, $this->at('2026-06-01 00:00'), $this->at('2026-06-30 23:59')));
        $this->assertSame(0, $metrics->pendapatanAdministrasi($branch->id, $this->at('2026-07-01 00:00'), $this->at('2026-07-31 23:59')));
        $this->assertSame(2_000_000, $metrics->penambahanModal($branch->id, $this->at('2026-06-01 00:00'), $this->at('2026-06-30 23:59')));

        // Pelunasan mengurangi baki pinjaman dan baki macet
        app(PaymentService::class)->settle($a, $this->at('2026-09-02 10:00'), null, null, $user);

        $this->assertSame(3_000_000, $metrics->bakiPinjaman($branch->id));
        $this->assertSame(['jumlah' => 3_000_000, 'count' => 1], $metrics->bakiMacet($branch->id, $this->at('2026-09-02 12:00')));
    }

    public function test_metrics_are_isolated_per_branch(): void
    {
        [$b1, $u1] = $this->branchWithUser('B01', 10_000_000);
        [$b2, $u2] = $this->branchWithUser('B02', 20_000_000);
        $loans = app(LoanService::class);
        $metrics = app(DashboardMetrics::class);

        $loans->disburse($this->borrower($b1, 'Budi'), 5_000_000, 10, 3, $this->at('2026-06-01 10:00'), $u1);
        $loans->disburse($this->borrower($b2, 'Ani'), 7_000_000, 10, 3, $this->at('2026-06-01 10:00'), $u2);

        $this->assertSame(5_000_000, $metrics->bakiPinjaman($b1->id));
        $this->assertSame(7_000_000, $metrics->bakiPinjaman($b2->id));
        $this->assertSame(500_000, $metrics->pendapatanAdministrasi($b1->id, $this->at('2026-06-01 00:00'), $this->at('2026-06-30 23:59')));
        $this->assertSame(700_000, $metrics->pendapatanAdministrasi($b2->id, $this->at('2026-06-01 00:00'), $this->at('2026-06-30 23:59')));
    }
}
