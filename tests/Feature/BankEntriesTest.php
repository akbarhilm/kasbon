<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\StatementService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankEntriesTest extends TestCase
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

        config(['pinjaman.timezone' => 'Asia/Jakarta']);

        $this->branch = Branch::create(['kode' => 'B01', 'nama' => 'Cabang 1']);

        $this->cabang = (new User)->forceFill([
            'name' => 'Admin 1', 'email' => 'a1@example.test', 'password' => 'rahasia-123',
            'role' => Role::Cabang, 'branch_id' => $this->branch->id,
        ]);
        $this->cabang->save();

        $this->post_(LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000, '2026-06-01 08:00');
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

    private function post_(LedgerPos $pos, Arah $arah, int $jumlah, string $waktu): void
    {
        app(LedgerService::class)->post(
            $this->branch->id, $pos, $arah, $jumlah, $this->at($waktu), keterangan: $pos->label(), userId: $this->cabang->id,
        );
    }

    public function test_bank_interest_increases_cash_balance_only(): void
    {
        $this->post_(LedgerPos::BungaBank, Arah::Masuk, 150_000, '2026-06-30 09:00');

        $ledger = app(LedgerService::class);
        $this->assertSame(10_150_000, $ledger->balance($this->branch->id));
        $this->assertSame(10_150_000, $ledger->cashBalance($this->branch->id));
        $this->assertSame(0, $ledger->adminBalance($this->branch->id));
    }

    public function test_bank_fee_reduces_cash_and_is_audited(): void
    {
        app(WithdrawalService::class)->bankFee($this->cabang, 25_000, $this->at('2026-06-20 09:00'), 'Biaya transfer');

        $this->assertSame(9_975_000, app(LedgerService::class)->cashBalance($this->branch->id));

        $log = AuditLog::where('aksi', 'biaya_bank.dicatat')->first();
        $this->assertNotNull($log);
        $this->assertSame('biaya_admin_bank', $log->data_sesudah['pos']);
        $this->assertSame(25_000, $log->data_sesudah['jumlah']);
    }

    public function test_bank_fee_cannot_exceed_cash_balance(): void
    {
        $this->expectException(ValidationException::class);
        app(WithdrawalService::class)->bankFee($this->cabang, 10_000_001, $this->at('2026-06-20 09:00'));
    }

    public function test_only_branch_users_can_record_bank_fee(): void
    {
        $sa = (new User)->forceFill([
            'name' => 'SA', 'email' => 'sa@example.test', 'password' => 'rahasia-123', 'role' => Role::SuperAdmin,
        ]);
        $sa->save();

        $this->expectException(AuthorizationException::class);
        app(WithdrawalService::class)->bankFee($sa, 1_000, $this->at('2026-06-20 09:00'));
    }

    public function test_statement_lists_bank_positions(): void
    {
        $this->post_(LedgerPos::BungaBank, Arah::Masuk, 150_000, '2026-06-30 09:00');
        app(WithdrawalService::class)->bankFee($this->cabang, 25_000, $this->at('2026-06-20 09:00'));

        $statement = app(StatementService::class)->build($this->branch->id, $this->at('2026-06-10 00:00'), $this->at('2026-06-30 23:59:59'));

        $positions = array_column($statement->perPos, null, 'pos');
        $this->assertSame(150_000, $positions['Bunga bank']['masuk']);
        $this->assertSame(25_000, $positions['Biaya admin bank']['keluar']);
        $this->assertSame(10_125_000, $statement->closing);
    }
}
