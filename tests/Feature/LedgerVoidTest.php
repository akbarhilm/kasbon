<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\LedgerVoid;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\IntegrityChecker;
use App\Services\LedgerService;
use App\Services\LedgerVoidService;
use App\Services\LoanDeletionService;
use App\Services\LoanService;
use App\Services\StatementService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class LedgerVoidTest extends TestCase
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

    private function entry(LedgerPos $pos, Arah $arah, int $jumlah, string $waktu = '2026-06-10 09:00'): LedgerEntry
    {
        return $this->ledger()->post($this->branch->id, $pos, $arah, $jumlah, $this->at($waktu), keterangan: $pos->label(), userId: $this->cabang->id);
    }

    private function void(LedgerEntry $entry, string $alasan = 'Salah input, dilaporkan cabang lewat telepon', ?User $by = null): LedgerVoid
    {
        return app(LedgerVoidService::class)->void($entry, $alasan, $by ?? $this->sa);
    }

    /** @return array<int, string> */
    private function errorCodes(): array
    {
        return array_values(array_map(
            fn (array $i): string => $i['kode'],
            array_filter(app(IntegrityChecker::class)->check(), fn (array $i): bool => $i['level'] === 'error'),
        ));
    }

    private function seedBalance(): void
    {
        $this->entry(LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000, '2026-06-01 08:00');
    }

    public function test_voiding_capital_reverses_cash_and_keeps_the_original_entry(): void
    {
        $this->seedBalance();
        $modal = $this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000);
        $this->assertSame(12_000_000, $this->ledger()->cashBalance($this->branch->id));

        $impact = app(LedgerVoidService::class)->impact($modal);
        $this->assertSame('saldo kas', $impact['kantong']);
        $this->assertSame(-2_000_000, $impact['perubahan']);
        $this->assertSame(10_000_000, $impact['setelah']);

        $void = $this->void($modal);

        $this->assertSame(10_000_000, $this->ledger()->cashBalance($this->branch->id));
        $this->assertNotNull(LedgerEntry::find($modal->id)); // entri asli tetap ada

        $reversal = $void->reversal;
        $this->assertSame(LedgerPos::Koreksi, $reversal->pos);
        $this->assertSame(Arah::Keluar, $reversal->arah);
        $this->assertSame(2_000_000, $reversal->jumlah);

        $log = AuditLog::where('aksi', 'entri_kas.dibatalkan')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->sa->id, $log->user_id);
        $this->assertSame('penambahan_modal', $log->data_sebelum['pos']);
        $this->assertSame([], $this->errorCodes());
    }

    public function test_voiding_bank_interest_and_bank_fee_restores_cash(): void
    {
        $this->seedBalance();

        $bunga = $this->entry(LedgerPos::BungaBank, Arah::Masuk, 150_000);
        $this->void($bunga);
        $this->assertSame(10_000_000, $this->ledger()->cashBalance($this->branch->id));

        $biaya = app(WithdrawalService::class)->bankFee($this->cabang, 25_000, $this->at('2026-06-20 09:00'));
        $this->assertSame(9_975_000, $this->ledger()->cashBalance($this->branch->id));

        $reversal = $this->void($biaya)->reversal;
        $this->assertSame(Arah::Masuk, $reversal->arah);
        $this->assertSame(10_000_000, $this->ledger()->cashBalance($this->branch->id));
    }

    public function test_voiding_cash_withdrawal_restores_cash(): void
    {
        $this->seedBalance();
        $tarik = app(WithdrawalService::class)->withdraw($this->cabang, WithdrawalService::KAS, 1_000_000, $this->at('2026-06-20 09:00'));

        $this->void($tarik);

        $this->assertSame(10_000_000, $this->ledger()->cashBalance($this->branch->id));
    }

    public function test_voiding_administration_withdrawal_restores_the_administration_pool(): void
    {
        $this->seedBalance();

        $borrower = Borrower::create([
            'branch_id' => $this->branch->id, 'nama' => 'Budi',
            'nik' => sprintf('%016d', random_int(1, 9999999999999999)), 'no_hp' => '0811',
        ]);
        app(LoanService::class)->disburse($borrower, 5_000_000, 10, 1, $this->at('2026-06-01 10:00'), $this->cabang);

        $tarik = app(WithdrawalService::class)->withdraw($this->cabang, WithdrawalService::ADMINISTRASI, 500_000, $this->at('2026-06-20 09:00'));
        $this->assertSame(0, $this->ledger()->adminBalance($this->branch->id));
        $cashBefore = $this->ledger()->cashBalance($this->branch->id);

        $impact = app(LedgerVoidService::class)->impact($tarik);
        $this->assertSame('saldo administrasi', $impact['kantong']);

        $reversal = $this->void($tarik)->reversal;

        $this->assertSame(LedgerPos::KoreksiAdministrasi, $reversal->pos);
        $this->assertSame(Arah::Masuk, $reversal->arah);
        $this->assertSame(500_000, $this->ledger()->adminBalance($this->branch->id));
        $this->assertSame($cashBefore, $this->ledger()->cashBalance($this->branch->id));
        $this->assertSame([], $this->errorCodes());
    }

    public function test_voided_opening_balance_can_be_entered_again(): void
    {
        $saldoAwal = $this->entry(LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000, '2026-06-01 08:00');
        $this->void($saldoAwal, 'Saldo awal salah ketik');

        $exists = LedgerEntry::query()
            ->where('branch_id', $this->branch->id)
            ->where('pos', LedgerPos::SaldoAwal)
            ->whereDoesntHave('void')
            ->exists();

        $this->assertFalse($exists); // tombol Saldo awal tampil kembali
        $this->assertSame(0, $this->ledger()->balance($this->branch->id));
    }

    public function test_only_super_admin_can_void(): void
    {
        $this->seedBalance();
        $modal = $this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000);

        $this->expectException(AuthorizationException::class);
        $this->void($modal, 'Salah input, dilaporkan cabang lewat telepon', $this->cabang);
    }

    public function test_reason_is_required(): void
    {
        $this->seedBalance();
        $modal = $this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000);

        $this->expectException(ValidationException::class);
        $this->void($modal, '  ');
    }

    public function test_entry_cannot_be_voided_twice(): void
    {
        $this->seedBalance();
        $modal = $this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000);
        $this->void($modal);

        $this->expectException(ValidationException::class);
        $this->void($modal);
    }

    public function test_loan_entries_and_reversal_entries_cannot_be_voided_here(): void
    {
        $this->seedBalance();

        $borrower = Borrower::create([
            'branch_id' => $this->branch->id, 'nama' => 'Budi',
            'nik' => sprintf('%016d', random_int(1, 9999999999999999)), 'no_hp' => '0811',
        ]);
        app(LoanService::class)->disburse($borrower, 5_000_000, 10, 1, $this->at('2026-06-01 10:00'), $this->cabang);
        $pencairan = LedgerEntry::where('pos', LedgerPos::Pinjaman)->first();

        try {
            $this->void($pencairan);
            $this->fail('Entri pinjaman seharusnya tidak dapat dibatalkan dari buku kas.');
        } catch (ValidationException) {
            $this->assertFalse(LedgerVoidService::isVoidable($pencairan));
        }

        $modal = $this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000);
        $reversal = $this->void($modal)->reversal;

        $this->assertFalse(LedgerVoidService::isVoidable($reversal));
    }

    public function test_dashboard_numbers_exclude_voided_entries_and_deleted_loans(): void
    {
        $this->seedBalance();
        $from = $this->at('2026-06-01 00:00');
        $to = $this->at('2026-06-30 23:59:59');
        $metrics = app(DashboardMetrics::class);

        $modal = $this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000);
        $this->assertSame(2_000_000, $metrics->penambahanModal($this->branch->id, $from, $to));
        $this->void($modal);
        $this->assertSame(0, $metrics->penambahanModal($this->branch->id, $from, $to));

        $borrower = Borrower::create([
            'branch_id' => $this->branch->id, 'nama' => 'Budi',
            'nik' => sprintf('%016d', random_int(1, 9999999999999999)), 'no_hp' => '0811',
        ]);
        $loan = app(LoanService::class)->disburse($borrower, 5_000_000, 10, 1, $this->at('2026-06-01 10:00'), $this->cabang);
        $this->assertSame(500_000, $metrics->pendapatanAdministrasi($this->branch->id, $from, $to));

        app(LoanDeletionService::class)->delete($loan, 'Pinjaman fiktif', $this->sa);
        $this->assertSame(0, $metrics->pendapatanAdministrasi($this->branch->id, $from, $to));
    }

    public function test_statement_marks_voided_entries_and_still_balances(): void
    {
        $this->seedBalance();
        $modal = $this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000, '2026-06-15 09:00');
        $this->void($modal);

        $statement = app(StatementService::class)->build($this->branch->id, $this->at('2026-06-10 00:00'), $this->at('2026-12-31 23:59:59'));

        $keterangan = array_column($statement->rows, 'keterangan');
        $this->assertTrue((bool) array_filter($keterangan, fn (string $k): bool => str_contains($k, '[dibatalkan]')));
        $this->assertSame($this->ledger()->balance($this->branch->id), $statement->closing);
    }

    public function test_integrity_check_detects_a_tampered_reversal(): void
    {
        $this->seedBalance();
        $void = $this->void($this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000));

        $this->assertSame([], $this->errorCodes());

        DB::table('ledger_entries')->where('id', $void->reversal_entry_id)->update(['jumlah' => 1]);

        $this->assertContains('VOID_TIDAK_SEIMBANG', $this->errorCodes());
    }

    public function test_void_records_are_immutable(): void
    {
        $this->seedBalance();
        $void = $this->void($this->entry(LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000));

        $this->expectException(LogicException::class);
        $void->update(['alasan' => 'diubah']);
    }
}
