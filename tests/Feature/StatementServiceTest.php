<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanService;
use App\Services\StatementService;
use App\Services\StatementXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StatementServiceTest extends TestCase
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

        $this->postEntry($branch, $user, LedgerPos::SaldoAwal, Arah::Masuk, $saldoAwal, '2026-06-01 08:00', 'Saldo awal');

        return [$branch, $user];
    }

    private function postEntry(Branch $branch, User $user, LedgerPos $pos, Arah $arah, int $jumlah, string $waktu, string $keterangan): void
    {
        app(LedgerService::class)->post(
            $branch->id, $pos, $arah, $jumlah, $this->at($waktu), keterangan: $keterangan, userId: $user->id,
        );
    }

    public function test_opening_running_balance_and_closing_for_one_branch(): void
    {
        [$branch, $user] = $this->branchWithUser('B01', 10_000_000);

        $borrower = Borrower::create(['branch_id' => $branch->id, 'nama' => 'Budi', 'no_hp' => '0811']);
        app(LoanService::class)->disburse($borrower, 5_000_000, 10, 3, $this->at('2026-06-01 10:00'), $user);

        $this->postEntry($branch, $user, LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000, '2026-06-15 09:00', 'Modal');
        $this->postEntry($branch, $user, LedgerPos::Penarikan, Arah::Keluar, 1_000_000, '2026-06-20 09:00', 'Tarik');

        $statement = app(StatementService::class)->build($branch->id, $this->at('2026-06-10 00:00'), $this->at('2026-06-30 23:59:59'));

        // Sebelum 10 Jun: 10.000.000 - 5.000.000 + 500.000
        $this->assertSame(5_500_000, $statement->opening);
        $this->assertCount(2, $statement->rows);
        $this->assertSame(7_500_000, $statement->rows[0]['saldo']);
        $this->assertSame(6_500_000, $statement->rows[1]['saldo']);
        $this->assertSame(2_000_000, $statement->totalMasuk);
        $this->assertSame(1_000_000, $statement->totalKeluar);
        $this->assertSame(6_500_000, $statement->closing);

        $this->assertSame(
            app(LedgerService::class)->balance($branch->id, $this->at('2026-06-30 23:59:59')),
            $statement->closing,
        );

        $this->assertSame(['Penambahan modal', 'Penarikan saldo'], array_column($statement->perPos, 'pos'));
        $this->assertSame([], $statement->perBranch);
    }

    public function test_loan_entries_show_borrower_name(): void
    {
        [$branch, $user] = $this->branchWithUser('B01', 10_000_000);
        $borrower = Borrower::create(['branch_id' => $branch->id, 'nama' => 'Budi', 'no_hp' => '0811']);
        app(LoanService::class)->disburse($borrower, 5_000_000, 10, 3, $this->at('2026-06-01 10:00'), $user);

        $statement = app(StatementService::class)->build($branch->id, $this->at('2026-06-01 09:00'), $this->at('2026-06-01 23:59'));

        $this->assertSame('Pencairan pinjaman - Budi', $statement->rows[0]['keterangan']);
    }

    public function test_consolidated_statement_sums_all_branches(): void
    {
        [$b1, $u1] = $this->branchWithUser('B01', 10_000_000);
        [$b2] = $this->branchWithUser('B02', 20_000_000);

        $borrower = Borrower::create(['branch_id' => $b1->id, 'nama' => 'Budi', 'no_hp' => '0811']);
        app(LoanService::class)->disburse($borrower, 5_000_000, 10, 3, $this->at('2026-06-01 10:00'), $u1);
        $this->postEntry($b1, $u1, LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000, '2026-06-15 09:00', 'Modal');
        $this->postEntry($b1, $u1, LedgerPos::Penarikan, Arah::Keluar, 1_000_000, '2026-06-20 09:00', 'Tarik');

        $statement = app(StatementService::class)->build(null, $this->at('2026-06-01 00:00'), $this->at('2026-06-30 23:59:59'));

        $this->assertSame(0, $statement->opening);
        $this->assertSame(32_500_000, $statement->totalMasuk);
        $this->assertSame(6_000_000, $statement->totalKeluar);
        $this->assertSame(26_500_000, $statement->closing);

        $this->assertCount(2, $statement->perBranch);
        $this->assertSame(6_500_000, $statement->perBranch[0]['akhir']);
        $this->assertSame(20_000_000, $statement->perBranch[1]['akhir']);
        $this->assertSame($b2->nama, $statement->perBranch[1]['cabang']);
    }

    public function test_excel_export_writes_text_as_text_not_formula(): void
    {
        [$branch, $user] = $this->branchWithUser('B01', 10_000_000);
        $this->postEntry($branch, $user, LedgerPos::PenambahanModal, Arah::Masuk, 2_000_000, '2026-06-15 09:00', '=1+1');

        $statement = app(StatementService::class)->build($branch->id, $this->at('2026-06-10 00:00'), $this->at('2026-06-30 23:59:59'));
        $response = app(StatementXlsx::class)->download($statement);

        ob_start();
        $response->sendContent();
        $binary = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'rk') . '.xlsx';
        file_put_contents($path, $binary);
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        // Baris 5 = header, 6 = saldo awal, 7 = entri pertama; kolom C = keterangan.
        $this->assertSame('Saldo awal', $sheet->getCell('C6')->getValue());
        $this->assertSame('=1+1', $sheet->getCell('C7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C7')->getDataType());
        $this->assertSame(2_000_000, (int) $sheet->getCell('D7')->getValue());
    }
}
