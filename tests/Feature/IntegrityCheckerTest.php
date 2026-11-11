<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use App\Services\IntegrityChecker;
use App\Services\LedgerService;
use App\Services\LoanDeletionService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrityCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cabang;
    private User $sa;
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

        $this->sa = (new User)->forceFill([
            'name' => 'SA', 'email' => 'sa@example.test', 'password' => 'rahasia-123', 'role' => Role::SuperAdmin,
        ]);
        $this->sa->save();

        app(LedgerService::class)->post(
            $this->branch->id, LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000,
            $this->at('2026-06-01 08:00'), keterangan: 'Saldo awal', userId: $this->cabang->id,
        );
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

    private function borrower(string $nama): Borrower
    {
        return Borrower::create(['branch_id' => $this->branch->id, 'nama' => $nama, 'no_hp' => '08' . random_int(100000, 999999)]);
    }

    /** Aktif (L2), lunas dengan denda (L1), dan terhapus (L3). */
    private function seedHealthyData(): void
    {
        $loans = app(LoanService::class);
        $payments = app(PaymentService::class);

        $l1 = $loans->disburse($this->borrower('Budi'), 3_000_000, 10, 3, $this->at('2026-06-01 10:00'), $this->cabang);
        $loans->disburse($this->borrower('Siti'), 2_000_000, 10, 3, $this->at('2026-06-01 11:00'), $this->cabang);
        $l3 = $loans->disburse($this->borrower('Agus'), 1_000_000, 10, 2, $this->at('2026-06-01 12:00'), $this->cabang);

        $payments->settle($l1, $this->at('2026-09-10 10:00'), null, null, $this->cabang);
        app(LoanDeletionService::class)->delete($l3, 'Salah input pinjaman', $this->sa);
    }

    /** @return array<int, string> */
    private function errorCodes(): array
    {
        $issues = app(IntegrityChecker::class)->check();

        return array_values(array_map(
            fn (array $i): string => $i['kode'],
            array_filter($issues, fn (array $i): bool => $i['level'] === 'error'),
        ));
    }

    public function test_healthy_data_has_no_errors(): void
    {
        $this->seedHealthyData();

        $this->assertSame([], $this->errorCodes());
    }

    public function test_tampered_ledger_amount_is_detected(): void
    {
        $this->seedHealthyData();

        DB::table('ledger_entries')->where('pos', 'administrasi')->update(['jumlah' => 1]);

        $this->assertContains('ADMIN_KAS', $this->errorCodes());
    }

    public function test_missing_active_flag_is_detected(): void
    {
        $this->seedHealthyData();

        DB::table('loans')->where('status', 'aktif')->whereNull('deleted_at')->update(['active_borrower_id' => null]);

        $this->assertContains('FLAG_AKTIF', $this->errorCodes());
    }

    public function test_tampered_due_date_is_detected(): void
    {
        $this->seedHealthyData();

        DB::table('loans')->where('status', 'aktif')->whereNull('deleted_at')->update(['jatuh_tempo' => '2027-01-01']);

        $this->assertContains('JATUH_TEMPO', $this->errorCodes());
    }

    public function test_missing_correction_for_deleted_loan_is_detected(): void
    {
        $this->seedHealthyData();

        DB::table('ledger_entries')->whereIn('pos', ['koreksi', 'koreksi_administrasi'])->delete();

        $this->assertContains('HAPUS_TIDAK_SEIMBANG', $this->errorCodes());
    }

    public function test_settled_loan_without_payment_is_detected(): void
    {
        $this->seedHealthyData();

        DB::table('payments')->delete();

        $this->assertContains('PEMBAYARAN_JUMLAH', $this->errorCodes());
    }
}
