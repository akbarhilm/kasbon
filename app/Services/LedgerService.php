<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Models\LedgerEntry;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Buku kas satu cabang terbagi dua "kantong":
 *  - kantong administrasi = Administrasi masuk - Penarikan administrasi - Koreksi administrasi
 *  - kantong kas          = semua entri lain (saldo awal, modal, pinjaman, pelunasan, denda, penarikan kas, koreksi)
 * Saldo akhir = saldo kas + saldo administrasi.
 */
final class LedgerService
{
    private const ADMIN_POOL = ['administrasi', 'penarikan_administrasi', 'koreksi_administrasi'];

    public function post(
        int $branchId,
        LedgerPos $pos,
        Arah $arah,
        int $jumlah,
        CarbonInterface $waktu,
        ?int $loanId = null,
        ?int $paymentId = null,
        ?string $keterangan = null,
        ?int $userId = null,
    ): LedgerEntry {
        if ($jumlah <= 0) {
            throw new InvalidArgumentException('Jumlah entri buku kas harus lebih dari nol.');
        }

        return LedgerEntry::create([
            'branch_id' => $branchId,
            'waktu' => $waktu,
            'pos' => $pos,
            'arah' => $arah,
            'jumlah' => $jumlah,
            'loan_id' => $loanId,
            'payment_id' => $paymentId,
            'keterangan' => $keterangan,
            'created_by' => $userId,
        ]);
    }

    /** Saldo akhir cabang (kas + administrasi), opsional sampai waktu tertentu. */
    public function balance(int $branchId, ?CarbonInterface $until = null): int
    {
        return $this->signedSum($branchId, null, $until);
    }

    /** Saldo administrasi yang belum ditarik (tanpa batas periode). */
    public function adminBalance(int $branchId): int
    {
        return $this->signedSum($branchId, self::ADMIN_POOL, null);
    }

    /** Saldo kas = saldo akhir dikurangi saldo administrasi (tanpa batas periode). */
    public function cashBalance(int $branchId): int
    {
        return $this->balance($branchId) - $this->adminBalance($branchId);
    }

    /** @param array<int, string>|null $onlyPos */
    private function signedSum(int $branchId, ?array $onlyPos, ?CarbonInterface $until): int
    {
        $query = LedgerEntry::query()->toBase()->where('branch_id', $branchId);

        if ($onlyPos !== null) {
            $query->whereIn('pos', $onlyPos);
        }

        if ($until !== null) {
            $query->where('waktu', '<=', $until);
        }

        $row = $query
            ->selectRaw("COALESCE(SUM(CASE WHEN arah = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS saldo")
            ->first();

        return (int) ($row->saldo ?? 0);
    }
}
