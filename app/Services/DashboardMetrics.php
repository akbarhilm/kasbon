<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Models\LedgerEntry;
use App\Models\Loan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Angka ringkasan dashboard per cabang. Dipakai oleh widget dan dapat diuji tanpa UI.
 */
final class DashboardMetrics
{
    public function __construct(private LedgerService $ledger)
    {
    }

    public function saldo(int $branchId): int
    {
        return $this->ledger->balance($branchId);
    }

    /** Baki pinjaman = total pokok pinjaman aktif (belum lunas). */
    public function bakiPinjaman(int $branchId): int
    {
        return (int) $this->activeLoans($branchId)->sum('pokok');
    }

    public function jumlahPinjamanAktif(int $branchId): int
    {
        return $this->activeLoans($branchId)->count();
    }

    /**
     * Baki macet = pokok pinjaman aktif yang sudah lewat jatuh tempo (batas hari macet menyusul di modul NPL).
     *
     * @return array{jumlah:int,count:int}
     */
    public function bakiMacet(int $branchId, ?CarbonInterface $asOf = null): array
    {
        $date = ($asOf ?? today())->toDateString();
        $query = $this->activeLoans($branchId)->whereDate('jatuh_tempo', '<', $date);

        return [
            'jumlah' => (int) (clone $query)->sum('pokok'),
            'count' => (clone $query)->count(),
        ];
    }

    public function pendapatanAdministrasi(int $branchId, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->ledgerSum($branchId, LedgerPos::Administrasi, $from, $to);
    }

    public function penambahanModal(int $branchId, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->ledgerSum($branchId, LedgerPos::PenambahanModal, $from, $to);
    }

    /** Saldo kas = saldo akhir dikurangi saldo administrasi. */
    public function saldoKas(int $branchId): int
    {
        return $this->ledger->cashBalance($branchId);
    }

    /** Saldo administrasi yang belum ditarik (tanpa batas periode). */
    public function saldoAdministrasi(int $branchId): int
    {
        return $this->ledger->adminBalance($branchId);
    }

    public function penarikanAdministrasi(int $branchId, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) LedgerEntry::query()
            ->where('branch_id', $branchId)
            ->where('pos', LedgerPos::PenarikanAdministrasi)
            ->where('arah', Arah::Keluar)
            ->whereBetween('waktu', [$from, $to])
            ->sum('jumlah');
    }

    private function activeLoans(int $branchId): Builder
    {
        return Loan::query()
            ->where('branch_id', $branchId)
            ->where('status', LoanStatus::Aktif);
    }

    private function ledgerSum(int $branchId, LedgerPos $pos, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) LedgerEntry::query()
            ->where('branch_id', $branchId)
            ->where('pos', $pos)
            ->where('arah', Arah::Masuk)
            ->whereBetween('waktu', [$from, $to])
            ->sum('jumlah');
    }
}
