<?php

namespace App\Services;

use App\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * Hasil laporan rekening koran.
 *
 * rows:      [waktu, cabang, pos, keterangan, masuk, keluar, saldo]
 * perPos:    [pos, masuk, keluar]
 * perBranch: [cabang, awal, masuk, keluar, akhir] (hanya untuk laporan konsolidasi)
 */
final class Statement
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $perPos
     * @param array<int, array<string, mixed>> $perBranch
     */
    public function __construct(
        public readonly ?Branch $branch,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $opening,
        public readonly array $rows,
        public readonly int $totalMasuk,
        public readonly int $totalKeluar,
        public readonly int $closing,
        public readonly array $perPos,
        public readonly array $perBranch,
    ) {
    }
}
