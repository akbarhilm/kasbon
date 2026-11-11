<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penghapusan pinjaman oleh super admin.
 * Pinjaman di-soft-delete (data tetap ada). Semua entri buku kas pinjaman itu (pencairan, administrasi,
 * pelunasan, denda) dibalik dengan entri Koreksi, sehingga saldo kembali seolah pinjaman tidak pernah ada.
 * Entri lama tidak diubah (buku kas append-only). Kejadian dicatat di audit log.
 */
final class LoanDeletionService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    /**
     * Dampak penghapusan terhadap saldo cabang, untuk ditampilkan sebelum SA mengonfirmasi.
     *
     * @return array{count:int,perubahan:int,sekarang:int,setelah:int}
     */
    public function impact(Loan $loan): array
    {
        $entries = $this->entriesToReverse($loan);
        $perubahan = -$this->net($entries);
        $sekarang = $this->ledger->balance($loan->branch_id);

        return [
            'count' => $entries->count(),
            'perubahan' => $perubahan,
            'sekarang' => $sekarang,
            'setelah' => $sekarang + $perubahan,
        ];
    }

    public function delete(Loan $loan, string $alasan, User $by): void
    {
        if ($by->role !== Role::SuperAdmin) {
            throw new AuthorizationException('Hanya super admin yang dapat menghapus pinjaman.');
        }

        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan penghapusan minimal 5 karakter.']);
        }

        DB::transaction(function () use ($loan, $alasan, $by): void {
            Branch::query()->lockForUpdate()->findOrFail($loan->branch_id);

            // Pinjaman yang sudah dihapus tidak ditemukan (global scope soft delete), sehingga tidak bisa dihapus dua kali.
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            $entries = $this->entriesToReverse($loan);
            $now = now();

            foreach ($entries as $entry) {
                $this->ledger->post(
                    $loan->branch_id,
                    // Koreksi atas entri administrasi mengurangi saldo administrasi (bukan saldo kas).
                    $entry->pos === LedgerPos::Administrasi ? LedgerPos::KoreksiAdministrasi : LedgerPos::Koreksi,
                    $entry->arah === Arah::Masuk ? Arah::Keluar : Arah::Masuk,
                    $entry->jumlah,
                    $now,
                    $loan->id,
                    $entry->payment_id,
                    "Koreksi hapus pinjaman #{$loan->id}: " . $entry->pos->label(),
                    $by->id,
                );
            }

            $before = Arr::only($loan->toArray(), [
                'id', 'branch_id', 'borrower_id', 'pokok', 'persen_admin', 'biaya_admin',
                'dicairkan_pada', 'tenor_bulan', 'jatuh_tempo', 'status', 'dilunasi_pada',
            ]);

            $loan->forceFill([
                'deleted_by' => $by->id,
                'alasan_hapus' => $alasan,
                'active_borrower_id' => null, // peminjam boleh mengajukan pinjaman baru
            ])->save();
            $loan->delete();

            $this->audit->record(
                'pinjaman.dihapus',
                $loan,
                $loan->branch_id,
                $alasan,
                $before,
                ['entri_dikoreksi' => $entries->count(), 'perubahan_saldo' => -$this->net($entries)],
                $by,
            );
        });
    }

    /** @return Collection<int, LedgerEntry> */
    private function entriesToReverse(Loan $loan): Collection
    {
        return LedgerEntry::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('pos', [LedgerPos::Koreksi->value, LedgerPos::KoreksiAdministrasi->value])
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, LedgerEntry> $entries */
    private function net(Collection $entries): int
    {
        return $entries->sum(fn (LedgerEntry $e): int => $e->arah === Arah::Masuk ? $e->jumlah : -$e->jumlah);
    }
}
