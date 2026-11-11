<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Models\Branch;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan rekening koran: saldo awal periode, mutasi buku kas dengan saldo berjalan, saldo akhir,
 * ringkasan per pos (cash flow). $branchId null = konsolidasi semua cabang.
 */
final class StatementService
{
    public function build(?int $branchId, CarbonInterface $from, CarbonInterface $to): Statement
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);

        $branch = $branchId ? Branch::query()->find($branchId) : null;
        $branchNames = Branch::query()->pluck('nama', 'id');

        $opening = $this->signedTotal($branchId, $from);

        $entries = LedgerEntry::query()
            ->with('loan.borrower')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('waktu', [$from, $to])
            ->orderBy('waktu')
            ->orderBy('id')
            ->get();

        $running = $opening;
        $rows = [];
        $totalMasuk = 0;
        $totalKeluar = 0;
        $byPos = [];
        $byBranch = [];

        foreach ($entries as $entry) {
            $masuk = $entry->arah === Arah::Masuk ? $entry->jumlah : 0;
            $keluar = $entry->arah === Arah::Keluar ? $entry->jumlah : 0;

            $running += $masuk - $keluar;
            $totalMasuk += $masuk;
            $totalKeluar += $keluar;

            $byPos[$entry->pos->value] ??= ['masuk' => 0, 'keluar' => 0];
            $byPos[$entry->pos->value]['masuk'] += $masuk;
            $byPos[$entry->pos->value]['keluar'] += $keluar;

            $byBranch[$entry->branch_id] ??= ['masuk' => 0, 'keluar' => 0];
            $byBranch[$entry->branch_id]['masuk'] += $masuk;
            $byBranch[$entry->branch_id]['keluar'] += $keluar;

            $borrower = $entry->loan?->borrower?->nama;

            $rows[] = [
                'waktu' => $entry->waktu,
                'cabang' => (string) ($branchNames[$entry->branch_id] ?? ''),
                'pos' => $entry->pos->label(),
                'keterangan' => trim(($entry->keterangan ?? '') . ($borrower ? ' - ' . $borrower : '')),
                'masuk' => $masuk,
                'keluar' => $keluar,
                'saldo' => $running,
            ];
        }

        $perPos = [];
        foreach (LedgerPos::cases() as $pos) {
            if (isset($byPos[$pos->value])) {
                $perPos[] = ['pos' => $pos->label()] + $byPos[$pos->value];
            }
        }

        return new Statement(
            branch: $branch,
            from: $from,
            to: $to,
            opening: $opening,
            rows: $rows,
            totalMasuk: $totalMasuk,
            totalKeluar: $totalKeluar,
            closing: $running,
            perPos: $perPos,
            perBranch: $branchId ? [] : $this->perBranch($from, $byBranch, $branchNames->all()),
        );
    }

    /** Saldo (masuk dikurangi keluar) seluruh entri sebelum $before. */
    private function signedTotal(?int $branchId, CarbonImmutable $before): int
    {
        $row = LedgerEntry::query()
            ->toBase()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('waktu', '<', $before)
            ->selectRaw("COALESCE(SUM(CASE WHEN arah = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS total")
            ->first();

        return (int) ($row->total ?? 0);
    }

    /**
     * @param array<int, array{masuk:int,keluar:int}> $byBranch
     * @param array<int, string> $names
     * @return array<int, array<string, mixed>>
     */
    private function perBranch(CarbonImmutable $from, array $byBranch, array $names): array
    {
        $openings = LedgerEntry::query()
            ->toBase()
            ->where('waktu', '<', $from)
            ->selectRaw("branch_id, COALESCE(SUM(CASE WHEN arah = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS total")
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id')
            ->all();

        $ids = array_unique(array_merge(array_keys($openings), array_keys($byBranch)));

        $result = [];
        foreach ($ids as $id) {
            $awal = (int) ($openings[$id] ?? 0);
            $masuk = $byBranch[$id]['masuk'] ?? 0;
            $keluar = $byBranch[$id]['keluar'] ?? 0;

            $result[] = [
                'cabang' => (string) ($names[$id] ?? ('Cabang ' . $id)),
                'awal' => $awal,
                'masuk' => $masuk,
                'keluar' => $keluar,
                'akhir' => $awal + $masuk - $keluar,
            ];
        }

        usort($result, fn (array $a, array $b): int => strcmp($a['cabang'], $b['cabang']));

        return $result;
    }
}
