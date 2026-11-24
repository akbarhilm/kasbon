<?php

namespace Tests\Feature;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\ModalDisetor;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModalDisetorTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposited_capital_is_a_memo_only_and_does_not_touch_the_ledger(): void
    {
        $a = Branch::create(['kode' => 'C01', 'nama' => 'Cabang A']);
        $b = Branch::create(['kode' => 'C02', 'nama' => 'Cabang B']);

        $sa = (new User)->forceFill([
            'name' => 'SA', 'email' => 'sa@example.test', 'password' => 'rahasia-123', 'role' => Role::SuperAdmin,
        ]);
        $sa->save();

        $ledger = app(LedgerService::class);
        $ledger->post($a->id, LedgerPos::SaldoAwal, Arah::Masuk, 10_000_000, CarbonImmutable::parse('2026-06-01 08:00'), keterangan: 'Saldo awal', userId: $sa->id);

        ModalDisetor::create(['branch_id' => $a->id, 'tanggal' => '2026-06-05', 'jumlah' => 5_000_000, 'keterangan' => 'Tahap 1', 'created_by' => $sa->id]);
        ModalDisetor::create(['branch_id' => $a->id, 'tanggal' => '2026-07-05', 'jumlah' => 3_000_000, 'created_by' => $sa->id]);
        ModalDisetor::create(['branch_id' => $b->id, 'tanggal' => '2026-06-05', 'jumlah' => 2_000_000, 'created_by' => $sa->id]);

        $metrics = app(DashboardMetrics::class);

        $this->assertSame(8_000_000, $metrics->modalDisetor($a->id));
        $this->assertSame(2_000_000, $metrics->modalDisetor($b->id));

        // Buku kas tidak berubah
        $this->assertSame(10_000_000, $ledger->balance($a->id));
        $this->assertSame(0, $ledger->balance($b->id));
        $this->assertSame(0, $metrics->penambahanModal($a->id, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')));
    }
}
