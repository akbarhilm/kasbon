<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanDeletionService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrossBranchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;
    private Branch $b;
    private User $userA;
    private User $userB;
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

        $this->a = Branch::create(['kode' => 'C01', 'nama' => 'Cabang A']);
        $this->b = Branch::create(['kode' => 'C02', 'nama' => 'Cabang B']);

        $this->userA = $this->branchUser($this->a, 'a@example.test');
        $this->userB = $this->branchUser($this->b, 'b@example.test');

        foreach ([[$this->a, $this->userA], [$this->b, $this->userB]] as [$branch, $user]) {
            app(LedgerService::class)->post(
                $branch->id, LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000,
                $this->at('2026-06-01 08:00'), keterangan: 'Saldo awal', userId: $user->id,
            );
        }
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

    private function branchUser(Branch $branch, string $email): User
    {
        $user = (new User)->forceFill([
            'name' => $email, 'email' => $email, 'password' => 'rahasia-123',
            'role' => Role::Cabang, 'branch_id' => $branch->id,
        ]);
        $user->save();

        return $user;
    }

    private function nik(int $n): string
    {
        return sprintf('%016d', 3273010000000000 + $n);
    }

    private function member(Branch $branch, string $nama, string $nik): Borrower
    {
        return Borrower::create(['branch_id' => $branch->id, 'nama' => $nama, 'nik' => $nik, 'no_hp' => '0811']);
    }

    private function disburse(Borrower $borrower, User $by, array $foto = []): Loan
    {
        return app(LoanService::class)->disburse($borrower, 1_000_000, 10, 3, $this->at('2026-06-01 10:00'), $by, $foto);
    }

    private function settle(Loan $loan, User $by): void
    {
        app(PaymentService::class)->settle($loan, $this->at('2026-08-01 10:00'), null, null, $by);
    }

    public function test_member_numbers_use_branch_letter_and_sequence(): void
    {
        $this->assertSame('A', $this->a->fresh()->kode_huruf);
        $this->assertSame('B', $this->b->fresh()->kode_huruf);

        $this->assertSame('A0001', $this->member($this->a, 'Budi', $this->nik(1))->nomor_anggota);
        $this->assertSame('A0002', $this->member($this->a, 'Siti', $this->nik(2))->nomor_anggota);
        $this->assertSame('B0001', $this->member($this->b, 'Agus', $this->nik(3))->nomor_anggota);
    }

    public function test_same_nik_cannot_be_registered_twice_in_one_branch(): void
    {
        $this->member($this->a, 'Andi', $this->nik(1));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->member($this->a, 'Andi lagi', $this->nik(1));
    }

    public function test_same_nik_can_register_in_other_branch_when_no_active_loan(): void
    {
        $inA = $this->member($this->a, 'Andi', $this->nik(1));
        $inB = $this->member($this->b, 'Andi', $this->nik(1));

        $this->assertSame('A0001', $inA->nomor_anggota);
        $this->assertSame('B0001', $inB->nomor_anggota);
    }

    public function test_registration_is_blocked_while_nik_has_active_loan_in_another_branch(): void
    {
        $andi = $this->member($this->a, 'Andi', $this->nik(1));
        $loan = $this->disburse($andi, $this->userA);

        try {
            $this->member($this->b, 'Andi', $this->nik(1));
            $this->fail('Pendaftaran di cabang lain seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cabang A', $e->errors()['nik'][0]);
        }

        $this->settle($loan, $this->userA);

        $this->assertSame('B0001', $this->member($this->b, 'Andi', $this->nik(1))->nomor_anggota);
    }

    public function test_loan_is_blocked_when_nik_has_active_loan_in_another_branch(): void
    {
        // Andi sudah terdaftar di cabang B sebelum meminjam di cabang A
        $andiB = $this->member($this->b, 'Andi', $this->nik(1));
        $andiA = $this->member($this->a, 'Andi', $this->nik(1));

        $loanA = $this->disburse($andiA, $this->userA);

        try {
            $this->disburse($andiB, $this->userB);
            $this->fail('Pinjaman di cabang B seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cabang A', $e->errors()['borrower_id'][0]);
        }

        $this->settle($loanA, $this->userA);

        $loanB = $this->disburse($andiB, $this->userB);
        $this->assertSame($this->nik(1), $loanB->fresh()->active_nik);
    }

    public function test_deleting_a_loan_releases_the_nik(): void
    {
        $sa = (new User)->forceFill([
            'name' => 'SA', 'email' => 'sa@example.test', 'password' => 'rahasia-123', 'role' => Role::SuperAdmin,
        ]);
        $sa->save();

        $loan = $this->disburse($this->member($this->a, 'Andi', $this->nik(1)), $this->userA);
        app(LoanDeletionService::class)->delete($loan, 'Salah input pinjaman', $sa);

        $this->assertSame('B0001', $this->member($this->b, 'Andi', $this->nik(1))->nomor_anggota);
    }

    public function test_loan_requires_nik(): void
    {
        $borrower = Borrower::create(['branch_id' => $this->a->id, 'nama' => 'Tanpa NIK', 'no_hp' => '0811']);

        $this->expectException(ValidationException::class);
        $this->disburse($borrower, $this->userA);
    }

    public function test_loan_history_counts_each_loan_of_a_member(): void
    {
        $andi = $this->member($this->a, 'Andi', $this->nik(1));

        $this->settle($this->disburse($andi, $this->userA), $this->userA);
        $this->disburse($andi, $this->userA);

        $this->assertSame(2, $andi->loans()->count());
    }

    public function test_loan_photos_are_stored_as_paths(): void
    {
        $andi = $this->member($this->a, 'Andi', $this->nik(1));
        $loan = $this->disburse($andi, $this->userA, ['foto-pinjaman/a.jpg', 'foto-pinjaman/b.jpg']);

        $this->assertSame(['foto-pinjaman/a.jpg', 'foto-pinjaman/b.jpg'], $loan->fresh()->foto);
    }

    /** Membutuhkan BorrowerObserver terdaftar di AppServiceProvider. */
    public function test_nik_changes_are_audited_in_masked_form_only(): void
    {
        $this->actingAs($this->userA);

        $andi = $this->member($this->a, 'Andi', $this->nik(1));
        $andi->update(['nik' => $this->nik(2)]);

        $log = AuditLog::where('aksi', 'peminjam.diubah')->first();
        $this->assertNotNull($log);
        $this->assertSame(Borrower::mask($this->nik(1)), $log->data_sebelum['nik']);
        $this->assertSame(Borrower::mask($this->nik(2)), $log->data_sesudah['nik']);
        $this->assertStringNotContainsString($this->nik(2), json_encode($log->data_sesudah));
    }
}
