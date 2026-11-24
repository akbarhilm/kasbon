<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Enums\Role;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\IntegrityChecker;
use App\Services\LedgerService;
use App\Services\LoanDeletionService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Tenor 2 dan 3 bulan = cicilan pokok per bulan; tenor 1 bulan = satu pembayaran. */
class InstallmentTest extends TestCase
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

        app(LedgerService::class)->post(
            $this->branch->id, LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000,
            $this->at('2026-01-01 08:00'), keterangan: 'Saldo awal', userId: $this->cabang->id,
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

    private function disburse(int $pokok, int $tenor, string $when = '2026-06-01 10:00'): Loan
    {
        $borrower = Borrower::create([
            'branch_id' => $this->branch->id, 'nama' => 'Budi',
            'nik' => sprintf('%016d', random_int(1, 9999999999999999)), 'no_hp' => '0811',
        ]);

        return app(LoanService::class)->disburse($borrower, $pokok, 10, $tenor, $this->at($when), $this->cabang);
    }

    private function pay(Loan $loan, string $paidAt)
    {
        return app(PaymentService::class)->payInstallment($loan, $this->at($paidAt), $this->cabang);
    }

    /** @return array<int, string> */
    private function errorCodes(): array
    {
        return array_values(array_map(
            fn (array $i): string => $i['kode'],
            array_filter(app(IntegrityChecker::class)->check(), fn (array $i): bool => $i['level'] === 'error'),
        ));
    }

    public function test_installments_are_created_per_month(): void
    {
        $loan = $this->disburse(3_000_000, 3);

        $rows = $loan->installments;
        $this->assertCount(3, $rows);
        $this->assertSame([1_000_000, 1_000_000, 1_000_000], $rows->pluck('pokok')->all());
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'], $rows->map(fn ($r) => $r->jatuh_tempo->toDateString())->all());
        $this->assertSame('2026-07-01', $loan->fresh()->jatuh_tempo->toDateString()); // cicilan pertama
        $this->assertSame(3_000_000, $loan->fresh()->sisa_pokok);
        $this->assertSame([], $this->errorCodes());
    }

    public function test_remainder_goes_to_the_last_installment(): void
    {
        $loan = $this->disburse(1_000_001, 3);

        $this->assertSame([333_333, 333_333, 333_335], $loan->installments->pluck('pokok')->all());
    }

    public function test_installment_due_dates_follow_month_end_rules(): void
    {
        $loan = $this->disburse(2_000_000, 2, '2026-01-31 10:00');

        $this->assertSame(['2026-02-28', '2026-03-31'], $loan->installments->map(fn ($r) => $r->jatuh_tempo->toDateString())->all());
    }

    public function test_tenor_one_is_a_single_payment(): void
    {
        $loan = $this->disburse(1_000_000, 1);

        $this->assertCount(1, $loan->installments);

        $this->pay($loan, '2026-07-01 10:00');

        $this->assertSame(LoanStatus::Lunas, $loan->fresh()->status);
        $this->assertSame(0, $loan->fresh()->sisa_pokok);
    }

    public function test_paying_installments_one_by_one_until_paid_off(): void
    {
        $loan = $this->disburse(2_000_000, 2);
        $this->assertSame(8_200_000, $this->balance()); // 10.000.000 - 2.000.000 + 200.000 admin

        $first = $this->pay($loan, '2026-07-01 10:00');

        $this->assertSame(1_000_000, $first->pokok);
        $this->assertSame(0, $first->denda_final);
        $fresh = $loan->fresh();
        $this->assertSame(LoanStatus::Aktif, $fresh->status);
        $this->assertSame(1_000_000, $fresh->sisa_pokok);
        $this->assertSame('2026-08-01', $fresh->jatuh_tempo->toDateString()); // cicilan berikutnya
        $this->assertNotNull($fresh->active_nik);
        $this->assertSame(9_200_000, $this->balance());

        $this->pay($loan, '2026-08-01 10:00');

        $done = $loan->fresh();
        $this->assertSame(LoanStatus::Lunas, $done->status);
        $this->assertSame(0, $done->sisa_pokok);
        $this->assertNull($done->active_nik);
        $this->assertNull($done->active_borrower_id);
        $this->assertSame('2026-08-01', $done->jatuh_tempo->toDateString());
        $this->assertSame(10_200_000, $this->balance());
        $this->assertSame([], $this->errorCodes());

        $this->expectException(ValidationException::class);
        $this->pay($loan, '2026-08-02 10:00');
    }

    public function test_penalty_is_calculated_per_installment_from_its_own_due_date(): void
    {
        $loan = $this->disburse(2_000_000, 2); // admin 200.000; cicilan jatuh tempo 1 Jul dan 1 Agu

        $first = $this->pay($loan, '2026-07-01 10:00'); // tepat waktu
        $second = $this->pay($loan, '2026-08-10 10:00'); // lewat 1 Agu + 3 hari

        $this->assertSame(0, $first->denda_final);
        $this->assertSame(200_000, $second->denda_final);
        $this->assertSame(10_400_000, $this->balance()); // 8.200.000 + 2.000.000 pokok + 200.000 denda
        $this->assertSame([], $this->errorCodes());
    }

    public function test_late_first_installment_does_not_penalize_the_next_one_yet(): void
    {
        $loan = $this->disburse(2_000_000, 2);

        $first = $this->pay($loan, '2026-07-10 10:00'); // lewat 1 Jul + 3 hari

        $this->assertSame(200_000, $first->denda_final);
        $this->assertSame('2026-08-01', $loan->fresh()->jatuh_tempo->toDateString());
    }

    public function test_dashboard_balances_follow_the_remaining_principal(): void
    {
        $loan = $this->disburse(2_000_000, 2);
        $metrics = app(DashboardMetrics::class);

        $this->assertSame(2_000_000, $metrics->bakiPinjaman($this->branch->id));

        $this->pay($loan, '2026-07-01 10:00');

        $this->assertSame(1_000_000, $metrics->bakiPinjaman($this->branch->id));
        $this->assertSame(['jumlah' => 0, 'count' => 0], $metrics->bakiMacet($this->branch->id, $this->at('2026-07-15 08:00')));
        $this->assertSame(['jumlah' => 1_000_000, 'count' => 1], $metrics->bakiMacet($this->branch->id, $this->at('2026-08-02 08:00')));
    }

    public function test_deleting_a_loan_midway_restores_the_original_balance(): void
    {
        $loan = $this->disburse(2_000_000, 2);
        $this->pay($loan, '2026-07-10 10:00'); // dengan denda

        app(LoanDeletionService::class)->delete($loan, 'Pinjaman fiktif', $this->sa);

        $this->assertSame(10_000_000, $this->balance());
        $this->assertSame([], $this->errorCodes());
    }

    public function test_integrity_check_detects_a_wrong_remaining_principal(): void
    {
        $loan = $this->disburse(2_000_000, 2);
        $this->pay($loan, '2026-07-01 10:00');

        DB::table('loans')->where('id', $loan->id)->update(['sisa_pokok' => 999]);

        $this->assertContains('SISA_POKOK', $this->errorCodes());
    }
}
