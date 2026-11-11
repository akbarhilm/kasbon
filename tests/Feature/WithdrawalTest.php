<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\LedgerService;
use App\Services\LoanDeletionService;
use App\Services\LoanService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cabang;
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

        $this->branch = Branch::create(['kode' => 'B01', 'nama' => 'Cabang 1']);

        $this->cabang = (new User)->forceFill([
            'name' => 'Admin 1', 'email' => 'a1@example.test', 'password' => 'rahasia-123',
            'role' => Role::Cabang, 'branch_id' => $this->branch->id,
        ]);
        $this->cabang->save();

        app(LedgerService::class)->post(
            $this->branch->id, LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000,
            $this->at('2026-06-01 08:00'), keterangan: 'Saldo awal', userId: $this->cabang->id,
        );

        // Pinjaman 5.000.000 dengan admin 10%: kas 5.000.000, administrasi 500.000, saldo akhir 5.500.000
        $borrower = Borrower::create(['branch_id' => $this->branch->id, 'nama' => 'Budi', 'no_hp' => '0811']);
        app(LoanService::class)->disburse($borrower, 5_000_000, 10, 3, $this->at('2026-06-01 10:00'), $this->cabang);
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

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function withdraw(string $source, int $jumlah)
    {
        return app(WithdrawalService::class)->withdraw($this->cabang, $source, $jumlah, $this->at('2026-06-20 09:00'), 'Tarik');
    }

    public function test_balance_is_split_into_cash_and_administration(): void
    {
        $this->assertSame(5_500_000, $this->ledger()->balance($this->branch->id));
        $this->assertSame(500_000, $this->ledger()->adminBalance($this->branch->id));
        $this->assertSame(5_000_000, $this->ledger()->cashBalance($this->branch->id));
    }

    public function test_administration_withdrawal_is_limited_to_administration_balance(): void
    {
        $service = app(WithdrawalService::class);
        $this->assertSame(500_000, $service->limit($this->branch->id, WithdrawalService::ADMINISTRASI));

        $this->withdraw(WithdrawalService::ADMINISTRASI, 500_000);

        $this->assertSame(0, $this->ledger()->adminBalance($this->branch->id));
        $this->assertSame(5_000_000, $this->ledger()->cashBalance($this->branch->id));
        $this->assertSame(5_000_000, $this->ledger()->balance($this->branch->id));

        $this->expectException(ValidationException::class);
        $this->withdraw(WithdrawalService::ADMINISTRASI, 1);
    }

    public function test_cash_withdrawal_is_limited_to_cash_balance(): void
    {
        try {
            $this->withdraw(WithdrawalService::KAS, 5_000_001);
            $this->fail('Penarikan melebihi saldo kas seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertSame(5_500_000, $this->ledger()->balance($this->branch->id));
        }

        $this->withdraw(WithdrawalService::KAS, 5_000_000);

        $this->assertSame(0, $this->ledger()->cashBalance($this->branch->id));
        $this->assertSame(500_000, $this->ledger()->adminBalance($this->branch->id));
    }

    public function test_cash_withdrawal_cannot_take_administration_money(): void
    {
        $this->withdraw(WithdrawalService::KAS, 5_000_000);

        // Saldo akhir masih 500.000 (administrasi), tetapi saldo kas sudah habis.
        $this->expectException(ValidationException::class);
        $this->withdraw(WithdrawalService::KAS, 1);
    }

    public function test_only_branch_users_can_withdraw(): void
    {
        $sa = (new User)->forceFill([
            'name' => 'SA', 'email' => 'sa@example.test', 'password' => 'rahasia-123', 'role' => Role::SuperAdmin,
        ]);
        $sa->save();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(WithdrawalService::class)->withdraw($sa, WithdrawalService::KAS, 1_000, $this->at('2026-06-20 09:00'));
    }

    public function test_withdrawal_is_audited_and_changes_dashboard_numbers(): void
    {
        $this->withdraw(WithdrawalService::ADMINISTRASI, 200_000);

        $log = AuditLog::where('aksi', 'penarikan.dicatat')->first();
        $this->assertNotNull($log);
        $this->assertSame('penarikan_administrasi', $log->data_sesudah['pos']);
        $this->assertSame(200_000, $log->data_sesudah['jumlah']);

        $metrics = app(DashboardMetrics::class);
        $from = $this->at('2026-06-01 00:00');
        $to = $this->at('2026-06-30 23:59:59');

        $this->assertSame(5_300_000, $metrics->saldo($this->branch->id));
        $this->assertSame(5_000_000, $metrics->saldoKas($this->branch->id));
        $this->assertSame(300_000, $metrics->saldoAdministrasi($this->branch->id));
        $this->assertSame(500_000, $metrics->pendapatanAdministrasi($this->branch->id, $from, $to)); // bruto
        $this->assertSame(200_000, $metrics->penarikanAdministrasi($this->branch->id, $from, $to));
    }

    public function test_deleting_a_loan_reduces_administration_balance_not_cash(): void
    {
        $sa = (new User)->forceFill([
            'name' => 'SA', 'email' => 'sa@example.test', 'password' => 'rahasia-123', 'role' => Role::SuperAdmin,
        ]);
        $sa->save();

        $loan = \App\Models\Loan::first();
        app(LoanDeletionService::class)->delete($loan, 'Salah input pinjaman', $sa);

        $this->assertSame(0, $this->ledger()->adminBalance($this->branch->id));
        $this->assertSame(10_000_000, $this->ledger()->cashBalance($this->branch->id));
        $this->assertSame(10_000_000, $this->ledger()->balance($this->branch->id));
    }
}
