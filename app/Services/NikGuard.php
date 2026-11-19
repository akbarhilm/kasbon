<?php

namespace App\Services;

use App\Models\Loan;

/** Satu NIK hanya boleh memiliki satu pinjaman aktif di seluruh cabang. */
final class NikGuard
{
    /** Pinjaman aktif milik NIK tersebut (di cabang mana pun), selain milik peminjam $exceptBorrowerId. */
    public function activeLoanFor(string $nik, ?int $exceptBorrowerId = null): ?Loan
    {
        return Loan::query()
            ->with('branch')
            ->where('active_nik', $nik)
            ->when($exceptBorrowerId, fn ($query) => $query->where('borrower_id', '!=', $exceptBorrowerId))
            ->first();
    }

    public static function registrationMessage(Loan $loan): string
    {
        $branch = $loan->branch?->nama ?? 'cabang lain';

        return "NIK ini masih memiliki pinjaman aktif di {$branch}. Pendaftaran ditolak sampai pinjaman tersebut lunas.";
    }

    public static function loanMessage(Loan $loan): string
    {
        $branch = $loan->branch?->nama ?? 'cabang lain';

        return "Peminjam masih memiliki pinjaman aktif di {$branch} (pinjaman #{$loan->id}). Pinjaman baru ditolak sampai lunas.";
    }
}
