<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LoanService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    /**
     * Mencairkan pinjaman. Asumsi: biaya admin dipotong saat pencairan,
     * sehingga kas keluar bersih = pokok - biaya admin (dicatat sebagai dua entri buku kas).
     */
    public function disburse(
        Borrower $borrower,
        int $pokok,
        float $persenAdmin,
        int $tenorMonths,
        CarbonInterface $waktu,
        User $by,
    ): Loan {
        $max = (int) config('pinjaman.max_tenor_months');

        if ($tenorMonths < 1 || $tenorMonths > $max) {
            throw ValidationException::withMessages(['tenor_bulan' => "Tenor harus 1 sampai {$max} bulan."]);
        }
        if ($pokok <= 0) {
            throw ValidationException::withMessages(['pokok' => 'Jumlah pinjaman harus lebih dari nol.']);
        }
        if ($persenAdmin < 0 || $persenAdmin > 100) {
            throw ValidationException::withMessages(['persen_admin' => 'Persentase biaya admin harus 0 sampai 100.']);
        }

        return DB::transaction(function () use ($borrower, $pokok, $persenAdmin, $tenorMonths, $waktu, $by) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($borrower->branch_id);
            $borrower = Borrower::query()->lockForUpdate()->findOrFail($borrower->id);

            $hasActive = Loan::query()
                ->where('borrower_id', $borrower->id)
                ->where('status', LoanStatus::Aktif)
                ->exists();

            if ($hasActive) {
                throw ValidationException::withMessages(['borrower_id' => 'Peminjam masih memiliki pinjaman aktif.']);
            }

            $fee = LoanTerms::adminFee($pokok, $persenAdmin);

            if ($this->ledger->balance($branch->id) < $pokok - $fee) {
                throw ValidationException::withMessages(['pokok' => 'Saldo cabang tidak mencukupi.']);
            }

            $loan = Loan::create([
                'branch_id' => $branch->id,
                'borrower_id' => $borrower->id,
                'pokok' => $pokok,
                'persen_admin' => $persenAdmin,
                'biaya_admin' => $fee,
                'dicairkan_pada' => $waktu,
                'tenor_bulan' => $tenorMonths,
                'jatuh_tempo' => LoanTerms::dueDate($waktu, $tenorMonths),
                'status' => LoanStatus::Aktif,
                'active_borrower_id' => $borrower->id,
                'created_by' => $by->id,
            ]);

            $this->ledger->post($branch->id, LedgerPos::Pinjaman, Arah::Keluar, $pokok, $waktu, $loan->id, null, 'Pencairan pinjaman', $by->id);

            if ($fee > 0) {
                $this->ledger->post($branch->id, LedgerPos::Administrasi, Arah::Masuk, $fee, $waktu, $loan->id, null, 'Biaya administrasi', $by->id);
            }

            $this->audit->record('pinjaman.dibuat', $loan, $branch->id, null, null, [
                'borrower_id' => $borrower->id,
                'pokok' => $pokok,
                'persen_admin' => $persenAdmin,
                'biaya_admin' => $fee,
                'tenor_bulan' => $tenorMonths,
                'jatuh_tempo' => $loan->jatuh_tempo->toDateString(),
            ], $by);

            return $loan;
        });
    }
}
