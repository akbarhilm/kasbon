<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Enums\Role;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class LoanFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
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
        ]);

        $this->branch = Branch::create(['kode' => 'B01', 'nama' => 'Cabang 1']);

        $this->user = (new User)->forceFill([
            'name' => 'Admin 1',
            'email' => 'a1@example.test',
            'password' => 'rahasia-123',
            'role' => Role::Cabang,
            'branch_id' => $this->branch->id,
        ]);
        $this->user->save();

        $this->borrower = Borrower::create([
            'branch_id' => $this->branch->id,
            'nama' => 'Budi',
            'no_hp' => '0811000111',
        ]);

        app(LedgerService::class)->post(
            $this->branch->id,
            LedgerPos::SaldoAwal,
            Arah::Masuk,
            10_000_000,
            $this->at('2026-06-01 08:00'),
            keterangan: 'Saldo awal',
            userId: $this->user->id,
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

    private function disburse(int $pokok = 5_000_000, int $tenor = 3): Loan
    {
        return app(LoanService::class)->disburse(
            $this->borrower,
            $pokok,
            10,
            $tenor,
            $this->at('2026-06-01 10:00'),
            $this->user,
        );
    }

    private function settle(Loan $loan, string $paidAt, ?int $denda = null, ?string $alasan = null)
    {
        return app(PaymentService::class)->settle($loan, $this->at($paidAt), $denda, $alasan, $this->user);
    }

    public function test_disbursement_posts_ledger_and_sets_due_date(): void
    {
        $loan = $this->disburse();

        $this->assertSame('2026-09-01', $loan->jatuh_tempo->toDateString());
        $this->assertSame(500_000, $loan->biaya_admin);
        // 10.000.000 - 5.000.000 (pinjaman) + 500.000 (administrasi)
        $this->assertSame(5_500_000, $this->balance());
    }

    public function test_second_active_loan_is_rejected(): void
    {
        $this->disburse();

        $this->expectException(ValidationException::class);
        $this->disburse();
    }

    public function test_tenor_above_maximum_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->disburse(1_000_000, 4);
    }

    public function test_insufficient_balance_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->disburse(25_000_000);
    }

    public function test_on_time_settlement_has_no_penalty_and_frees_borrower(): void
    {
        $loan = $this->disburse();
        $payment = $this->settle($loan, '2026-09-01 10:00');

        $this->assertSame(0, $payment->denda_final);
        $this->assertSame(LoanStatus::Lunas, $loan->fresh()->status);
        $this->assertNull($loan->fresh()->active_borrower_id);
        $this->assertSame(10_500_000, $this->balance());

        $this->disburse(1_000_000, 1); // peminjam boleh meminjam lagi
        $this->addToAssertionCount(1);
    }

    public function test_late_settlement_adds_one_period_penalty(): void
    {
        $loan = $this->disburse();
        $payment = $this->settle($loan, '2026-09-10 10:00');

        $this->assertSame(500_000, $payment->denda_sistem);
        $this->assertSame(500_000, $payment->denda_final);
        $this->assertSame(11_000_000, $this->balance());
    }

    public function test_forty_days_late_adds_two_periods_penalty(): void
    {
        $loan = $this->disburse();
        $payment = $this->settle($loan, '2026-10-11 10:00');

        $this->assertSame(1_000_000, $payment->denda_final);
    }

    public function test_changing_penalty_requires_reason(): void
    {
        $loan = $this->disburse();

        try {
            $this->settle($loan, '2026-09-10 10:00', 0, null);
            $this->fail('Perubahan denda tanpa alasan seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertSame(LoanStatus::Aktif, $loan->fresh()->status);
        }

        $payment = $this->settle($loan, '2026-09-10 10:00', 0, 'Dispensasi atas persetujuan pimpinan');

        $this->assertSame(500_000, $payment->denda_sistem);
        $this->assertSame(0, $payment->denda_final);
        $this->assertSame(10_500_000, $this->balance());
        $this->assertSame(0, LedgerEntry::where('pos', LedgerPos::Denda)->count());
    }

    public function test_ledger_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = LedgerEntry::first();

        $this->expectException(LogicException::class);
        $entry->update(['jumlah' => 1]);
    }
}
