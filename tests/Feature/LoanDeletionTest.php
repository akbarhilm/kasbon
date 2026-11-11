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
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanDeletionService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class LoanDeletionTest extends TestCase
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
        ]);

        $this->branch = Branch::create(['kode' => 'B01', 'nama' => 'Cabang 1']);

        $this->cabang = (new User)->forceFill([
            'name' => 'Admin 1', 'email' => 'a1@example.test', 'password' => 'rahasia-123',
            'role' => Role::Cabang, 'branch_id' => $this->branch->id,
        ]);
        $this->cabang->save();

        $this->sa = (new User)->forceFill([
            'name' => 'Super Admin', 'email' => 'sa@example.test', 'password' => 'rahasia-123',
            'role' => Role::SuperAdmin,
        ]);
        $this->sa->save();

        $this->borrower = Borrower::create(['branch_id' => $this->branch->id, 'nama' => 'Budi', 'no_hp' => '0811']);

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

    private function disburse(): Loan
    {
        return app(LoanService::class)->disburse($this->borrower, 5_000_000, 10, 3, $this->at('2026-06-01 10:00'), $this->cabang);
    }

    public function test_deleting_active_loan_reverses_ledger_and_frees_borrower(): void
    {
        $loan = $this->disburse();
        $this->assertSame(5_500_000, $this->balance());

        app(LoanDeletionService::class)->delete($loan, 'Salah input pinjaman', $this->sa);

        $this->assertSame(10_000_000, $this->balance());

        $deleted = Loan::withTrashed()->find($loan->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame($this->sa->id, $deleted->deleted_by);
        $this->assertSame('Salah input pinjaman', $deleted->alasan_hapus);
        $this->assertNull($deleted->active_borrower_id);
        $this->assertNull(Loan::find($loan->id));

        $this->assertSame(2, LedgerEntry::whereIn('pos', ['koreksi', 'koreksi_administrasi'])->count());

        $log = AuditLog::where('aksi', 'pinjaman.dihapus')->first();
        $this->assertNotNull($log);
        $this->assertSame('Salah input pinjaman', $log->keterangan);
        $this->assertSame($this->sa->id, $log->user_id);
        $this->assertSame(2, $log->data_sesudah['entri_dikoreksi']);

        // Peminjam boleh mengajukan pinjaman baru
        $this->disburse();
        $this->addToAssertionCount(1);
    }

    public function test_deleting_settled_loan_reverses_all_entries_including_penalty(): void
    {
        $loan = $this->disburse();
        app(PaymentService::class)->settle($loan, $this->at('2026-09-10 10:00'), null, null, $this->cabang);
        $this->assertSame(11_000_000, $this->balance()); // pokok kembali + admin 500.000 + denda 500.000

        $impact = app(LoanDeletionService::class)->impact($loan);
        $this->assertSame(4, $impact['count']);
        $this->assertSame(-1_000_000, $impact['perubahan']);

        app(LoanDeletionService::class)->delete($loan, 'Pinjaman fiktif', $this->sa);

        $this->assertSame(10_000_000, $this->balance());
        $this->assertSame(4, LedgerEntry::whereIn('pos', ['koreksi', 'koreksi_administrasi'])->count());
    }

    public function test_original_ledger_entries_are_kept(): void
    {
        $loan = $this->disburse();
        $before = LedgerEntry::count();

        app(LoanDeletionService::class)->delete($loan, 'Salah input pinjaman', $this->sa);

        $this->assertSame($before + 2, LedgerEntry::count());
        $this->assertSame(1, LedgerEntry::where('pos', LedgerPos::Pinjaman)->count());
    }

    public function test_only_super_admin_can_delete(): void
    {
        $loan = $this->disburse();

        try {
            app(LoanDeletionService::class)->delete($loan, 'Salah input pinjaman', $this->cabang);
            $this->fail('User cabang seharusnya tidak boleh menghapus pinjaman.');
        } catch (AuthorizationException) {
            $this->assertNotNull(Loan::find($loan->id));
            $this->assertSame(5_500_000, $this->balance());
        }
    }

    public function test_reason_is_required(): void
    {
        $loan = $this->disburse();

        $this->expectException(ValidationException::class);
        app(LoanDeletionService::class)->delete($loan, '   ', $this->sa);
    }

    public function test_loan_cannot_be_deleted_twice(): void
    {
        $loan = $this->disburse();
        app(LoanDeletionService::class)->delete($loan, 'Salah input pinjaman', $this->sa);

        $this->expectException(ModelNotFoundException::class);
        app(LoanDeletionService::class)->delete($loan, 'Salah input pinjaman', $this->sa);
    }

    public function test_disbursement_and_penalty_adjustment_are_audited(): void
    {
        $loan = $this->disburse();
        $this->assertSame(1, AuditLog::where('aksi', 'pinjaman.dibuat')->count());

        app(PaymentService::class)->settle($loan, $this->at('2026-09-10 10:00'), 0, 'Dispensasi pimpinan', $this->cabang);

        $this->assertSame(1, AuditLog::where('aksi', 'pembayaran.dicatat')->count());

        $log = AuditLog::where('aksi', 'denda.disesuaikan')->first();
        $this->assertNotNull($log);
        $this->assertSame('Dispensasi pimpinan', $log->keterangan);
        $this->assertSame(500_000, $log->data_sebelum['denda']);
        $this->assertSame(0, $log->data_sesudah['denda']);
    }

    public function test_borrower_contact_changes_are_audited(): void
    {
        $this->actingAs($this->cabang);

        $this->borrower->update(['no_hp' => '0822', 'no_rekening' => '123-456']);

        $log = AuditLog::where('aksi', 'peminjam.diubah')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->cabang->id, $log->user_id);
        $this->assertSame('0811', $log->data_sebelum['no_hp']);
        $this->assertSame('0822', $log->data_sesudah['no_hp']);
        $this->assertSame('123-456', $log->data_sesudah['no_rekening']);
    }

    public function test_audit_log_is_immutable(): void
    {
        $this->disburse();
        $log = AuditLog::first();

        $this->expectException(LogicException::class);
        $log->update(['keterangan' => 'diubah']);
    }
}
