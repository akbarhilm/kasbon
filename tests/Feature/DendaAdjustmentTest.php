<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Services\DendaAdjustmentService;
use App\Services\IntegrityChecker;
use App\Services\LedgerService;
use App\Services\LoanDeletionService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DendaAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cabang;
    private User $sa;
    private Borrower $borrower;
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
            'pinjaman.penalty_grace_days' => 3,
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

        $this->borrower = Borrower::create([
            'branch_id' => $this->branch->id, 'nama' => 'Budi',
            'nik' => sprintf('%016d', random_int(1, 9999999999999999)), 'no_hp' => '0811',
        ]);

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

    private function balance(): int
    {
        return app(LedgerService::class)->balance($this->branch->id);
    }

    /** Pinjaman 5.000.000, admin 10% = 500.000, jatuh tempo 1 Sep, denda mulai 5 Sep. */
    private function loan(): Loan
    {
        return app(LoanService::class)->disburse($this->borrower, 5_000_000, 10, 1, $this->at('2026-06-01 10:00'), $this->cabang);
    }

    private function settle(Loan $loan, string $paidAt): Payment
    {
        return app(PaymentService::class)->settle($loan, $this->at($paidAt), $this->cabang);
    }

    private function adjust(Payment $payment, int $baru, string $alasan = 'Dispensasi atas persetujuan pimpinan', ?User $by = null): Payment
    {
        return app(DendaAdjustmentService::class)->adjust($payment, $baru, $alasan, $by ?? $this->sa);
    }

    /** @return array<int, string> */
    private function errorCodes(): array
    {
        return array_values(array_map(
            fn (array $i): string => $i['kode'],
            array_filter(app(IntegrityChecker::class)->check(), fn (array $i): bool => $i['level'] === 'error'),
        ));
    }

    public function test_no_penalty_in_first_three_days_and_penalty_on_the_fourth(): void
    {
        $this->assertSame(0, $this->settle($this->loan(), '2026-07-04 23:00')->denda_final);

        $other = Borrower::create(['branch_id' => $this->branch->id, 'nama' => 'Siti', 'nik' => sprintf('%016d', random_int(1, 9999999999999999)), 'no_hp' => '0812']);
        $loan = app(LoanService::class)->disburse($other, 5_000_000, 10, 1, $this->at('2026-06-01 10:00'), $this->cabang);

        $this->assertSame(500_000, $this->settle($loan, '2026-07-05 00:30')->denda_final);
    }

    public function test_branch_cannot_change_the_penalty_at_payment(): void
    {
        $payment = $this->settle($this->loan(), '2026-07-10 10:00');

        $this->assertSame(500_000, $payment->denda_sistem);
        $this->assertSame(500_000, $payment->denda_final);
        $this->assertNull($payment->alasan_penyesuaian);
        $this->assertSame(11_000_000, $this->balance());
    }

    public function test_super_admin_cannot_record_payment(): void
    {
        $loan = $this->loan();

        $this->expectException(AuthorizationException::class);
        app(PaymentService::class)->settle($loan, $this->at('2026-07-10 10:00'), $this->sa);
    }

    public function test_super_admin_can_waive_the_penalty_after_payment(): void
    {
        $payment = $this->settle($this->loan(), '2026-07-10 10:00');

        $this->adjust($payment, 0);

        $fresh = $payment->fresh();
        $this->assertSame(500_000, $fresh->denda_sistem);
        $this->assertSame(0, $fresh->denda_final);
        $this->assertSame('Dispensasi atas persetujuan pimpinan', $fresh->alasan_penyesuaian);
        $this->assertSame(10_500_000, $this->balance());

        $entry = LedgerEntry::where('pos', LedgerPos::KoreksiDenda)->first();
        $this->assertNotNull($entry);
        $this->assertSame(Arah::Keluar, $entry->arah);
        $this->assertSame(500_000, $entry->jumlah);

        $log = AuditLog::where('aksi', 'denda.disesuaikan')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->sa->id, $log->user_id);
        $this->assertSame(500_000, $log->data_sebelum['denda']);
        $this->assertSame(0, $log->data_sesudah['denda']);

        $this->assertSame([], $this->errorCodes());
    }

    public function test_penalty_can_be_reduced_partially_and_restored(): void
    {
        $payment = $this->settle($this->loan(), '2026-07-10 10:00');

        $this->adjust($payment, 200_000);
        $this->assertSame(10_700_000, $this->balance());

        $this->adjust($payment->fresh(), 500_000, 'Dikembalikan sesuai hitungan sistem');
        $this->assertSame(11_000_000, $this->balance());
        $this->assertSame(2, LedgerEntry::where('pos', LedgerPos::KoreksiDenda)->count());
        $this->assertSame([], $this->errorCodes());
    }

    public function test_penalty_cannot_exceed_the_system_value(): void
    {
        $payment = $this->settle($this->loan(), '2026-07-10 10:00');

        $this->expectException(ValidationException::class);
        $this->adjust($payment, 600_000);
    }

    public function test_only_super_admin_can_adjust(): void
    {
        $payment = $this->settle($this->loan(), '2026-07-10 10:00');

        $this->expectException(AuthorizationException::class);
        $this->adjust($payment, 0, 'Dispensasi atas persetujuan pimpinan', $this->cabang);
    }

    public function test_reason_is_required(): void
    {
        $payment = $this->settle($this->loan(), '2026-07-10 10:00');

        $this->expectException(ValidationException::class);
        $this->adjust($payment, 0, '  ');
    }

    public function test_deleting_the_loan_after_an_adjustment_restores_the_original_balance(): void
    {
        $loan = $this->loan();
        $payment = $this->settle($loan, '2026-07-10 10:00');
        $this->adjust($payment, 0);

        app(LoanDeletionService::class)->delete($loan, 'Pinjaman fiktif', $this->sa);

        $this->assertSame(10_000_000, $this->balance());
        $this->assertSame([], $this->errorCodes());
    }

    public function test_integrity_check_detects_penalty_above_system_value(): void
    {
        $this->settle($this->loan(), '2026-07-10 10:00');

        DB::table('payments')->update(['denda_final' => 900_000]);

        $this->assertContains('DENDA_MELEBIHI_SISTEM', $this->errorCodes());
    }
}
