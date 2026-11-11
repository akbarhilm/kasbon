<?php

namespace App\Services;

use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Memeriksa konsistensi data: pinjaman, pembayaran, dan buku kas harus saling cocok.
 * level error = data tidak konsisten; warning = patut diperhatikan (mis. saldo negatif).
 */
final class IntegrityChecker
{
    public function __construct(private LedgerService $ledger)
    {
    }

    /** @return array<int, array{level:string,kode:string,objek:string,pesan:string}> */
    public function check(): array
    {
        $issues = [];
        $add = function (string $level, string $kode, string $objek, string $pesan) use (&$issues): void {
            $issues[] = ['level' => $level, 'kode' => $kode, 'objek' => $objek, 'pesan' => $pesan];
        };

        $maxTenor = (int) config('pinjaman.max_tenor_months');
        $ledger = $this->ledgerByLoan();
        $payments = Payment::query()->get()->groupBy('loan_id');

        foreach (Loan::withTrashed()->orderBy('id')->cursor() as $loan) {
            $obj = "Pinjaman #{$loan->id}";
            $pokok = (int) $loan->pokok;
            $isActive = $loan->status === LoanStatus::Aktif && ! $loan->trashed();

            if ($isActive && (int) $loan->active_borrower_id !== (int) $loan->borrower_id) {
                $add('error', 'FLAG_AKTIF', $obj, 'Pinjaman aktif tetapi penanda pinjaman aktif peminjam tidak terisi benar.');
            }
            if (! $isActive && $loan->active_borrower_id !== null) {
                $add('error', 'FLAG_TIDAK_AKTIF', $obj, 'Pinjaman tidak aktif tetapi penanda pinjaman aktif masih terisi.');
            }
            if ($loan->tenor_bulan < 1 || $loan->tenor_bulan > $maxTenor) {
                $add('error', 'TENOR', $obj, "Tenor {$loan->tenor_bulan} bulan di luar batas 1 sampai {$maxTenor}.");
            }

            $expectedDue = LoanTerms::dueDate($loan->dicairkan_pada, (int) $loan->tenor_bulan)->toDateString();
            if ($loan->jatuh_tempo->toDateString() !== $expectedDue) {
                $add('error', 'JATUH_TEMPO', $obj, "Jatuh tempo {$loan->jatuh_tempo->toDateString()} tidak sama dengan pencairan + tenor ({$expectedDue}).");
            }

            if ((int) $loan->biaya_admin !== LoanTerms::adminFee($pokok, $loan->persen_admin)) {
                $add('error', 'BIAYA_ADMIN', $obj, 'Biaya admin tidak sama dengan persentase x pokok.');
            }

            $byPos = $ledger[(int) $loan->id] ?? [];
            $in = fn (LedgerPos $p): int => (int) ($byPos[$p->value]['masuk'] ?? 0);
            $out = fn (LedgerPos $p): int => (int) ($byPos[$p->value]['keluar'] ?? 0);

            if ($loan->trashed()) {
                $net = 0;
                foreach ($byPos as $byArah) {
                    $net += (int) ($byArah['masuk'] ?? 0) - (int) ($byArah['keluar'] ?? 0);
                }
                if ($net !== 0) {
                    $add('error', 'HAPUS_TIDAK_SEIMBANG', $obj, "Pinjaman terhapus tetapi entri buku kasnya belum seimbang (selisih {$net}).");
                }

                continue;
            }

            if ($out(LedgerPos::Pinjaman) !== $pokok) {
                $add('error', 'PINJAMAN_KAS', $obj, 'Entri pencairan di buku kas tidak sama dengan pokok pinjaman.');
            }
            if ($in(LedgerPos::Administrasi) !== (int) $loan->biaya_admin) {
                $add('error', 'ADMIN_KAS', $obj, 'Entri administrasi di buku kas tidak sama dengan biaya admin pinjaman.');
            }
            if (isset($byPos[LedgerPos::Koreksi->value]) || isset($byPos[LedgerPos::KoreksiAdministrasi->value])) {
                $add('error', 'KOREKSI_TANPA_HAPUS', $obj, 'Ada entri koreksi pada pinjaman yang tidak dihapus.');
            }

            $loanPayments = $payments[$loan->id] ?? collect();

            if ($loan->status === LoanStatus::Lunas) {
                if ($loan->dilunasi_pada === null) {
                    $add('error', 'LUNAS_TANPA_TANGGAL', $obj, 'Pinjaman lunas tetapi tanggal pelunasan kosong.');
                }

                if ($loanPayments->count() !== 1) {
                    $add('error', 'PEMBAYARAN_JUMLAH', $obj, 'Pinjaman lunas harus memiliki tepat 1 pembayaran, ditemukan ' . $loanPayments->count() . '.');
                } else {
                    $payment = $loanPayments->first();

                    if ((int) $payment->pokok !== $pokok) {
                        $add('error', 'PEMBAYARAN_POKOK', $obj, 'Pokok pembayaran tidak sama dengan pokok pinjaman.');
                    }
                    if ($in(LedgerPos::PelunasanPokok) !== $pokok) {
                        $add('error', 'PELUNASAN_KAS', $obj, 'Entri pelunasan pokok di buku kas tidak sama dengan pokok pinjaman.');
                    }
                    if ($in(LedgerPos::Denda) !== (int) $payment->denda_final) {
                        $add('error', 'DENDA_KAS', $obj, 'Entri denda di buku kas tidak sama dengan denda pembayaran.');
                    }
                }
            } elseif ($loanPayments->isNotEmpty() || $in(LedgerPos::PelunasanPokok) > 0 || $in(LedgerPos::Denda) > 0) {
                $add('error', 'AKTIF_ADA_PEMBAYARAN', $obj, 'Pinjaman aktif tetapi sudah memiliki pembayaran atau entri pelunasan.');
            }
        }

        $duplicates = Loan::query()
            ->where('status', LoanStatus::Aktif)
            ->select('borrower_id', DB::raw('COUNT(*) AS jumlah'))
            ->groupBy('borrower_id')
            ->having('jumlah', '>', 1)
            ->pluck('jumlah', 'borrower_id');

        foreach ($duplicates as $borrowerId => $jumlah) {
            $add('error', 'PINJAMAN_GANDA', "Peminjam #{$borrowerId}", "Memiliki {$jumlah} pinjaman aktif.");
        }

        $invalid = LedgerEntry::query()->where('jumlah', '<=', 0)->count();
        if ($invalid > 0) {
            $add('error', 'BUKU_KAS_JUMLAH', 'Buku kas', "{$invalid} entri dengan jumlah nol atau negatif.");
        }

        foreach (Branch::query()->orderBy('id')->get() as $branch) {
            $obj = "Cabang {$branch->nama}";

            if ($this->ledger->balance($branch->id) < 0) {
                $add('warning', 'SALDO_NEGATIF', $obj, 'Saldo akhir cabang negatif.');
            }
            if ($this->ledger->cashBalance($branch->id) < 0) {
                $add('warning', 'KAS_NEGATIF', $obj, 'Saldo kas cabang negatif (dicairkan memakai administrasi yang belum ditarik).');
            }
            if ($this->ledger->adminBalance($branch->id) < 0) {
                $add('warning', 'ADMINISTRASI_NEGATIF', $obj, 'Saldo administrasi cabang negatif.');
            }
        }

        return $issues;
    }

    /** @return array<int, array<string, array<string, int>>> loan_id => pos => arah => total */
    private function ledgerByLoan(): array
    {
        $rows = LedgerEntry::query()
            ->toBase()
            ->whereNotNull('loan_id')
            ->selectRaw('loan_id, pos, arah, SUM(jumlah) AS total')
            ->groupBy('loan_id', 'pos', 'arah')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->loan_id][$row->pos][$row->arah] = (int) $row->total;
        }

        return $map;
    }
}
