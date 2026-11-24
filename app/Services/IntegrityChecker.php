<?php

namespace App\Services;

use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\LedgerVoid;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Memeriksa konsistensi data: pinjaman, cicilan, pembayaran, dan buku kas harus saling cocok.
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
        $installments = LoanInstallment::query()->orderBy('no')->get()->groupBy('loan_id');

        foreach (Loan::withTrashed()->with('borrower')->orderBy('id')->lazy() as $loan) {
            $obj = "Pinjaman #{$loan->id}";
            $pokok = (int) $loan->pokok;
            $isActive = $loan->status === LoanStatus::Aktif && ! $loan->trashed();

            if ($isActive && (int) $loan->active_borrower_id !== (int) $loan->borrower_id) {
                $add('error', 'FLAG_AKTIF', $obj, 'Pinjaman aktif tetapi penanda pinjaman aktif peminjam tidak terisi benar.');
            }
            if (! $isActive && $loan->active_borrower_id !== null) {
                $add('error', 'FLAG_TIDAK_AKTIF', $obj, 'Pinjaman tidak aktif tetapi penanda pinjaman aktif masih terisi.');
            }

            $nik = $loan->borrower?->nik;
            if ($isActive && $loan->active_nik !== $nik) {
                $add('error', 'FLAG_NIK', $obj, 'Penanda NIK pinjaman aktif tidak sama dengan NIK peminjam.');
            }
            if (! $isActive && $loan->active_nik !== null) {
                $add('error', 'FLAG_NIK_TIDAK_AKTIF', $obj, 'Pinjaman tidak aktif tetapi penanda NIK aktif masih terisi.');
            }

            if ($loan->tenor_bulan < 1 || $loan->tenor_bulan > $maxTenor) {
                $add('error', 'TENOR', $obj, "Tenor {$loan->tenor_bulan} bulan di luar batas 1 sampai {$maxTenor}.");
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

            // ---- Cicilan ----
            $rows = $installments[$loan->id] ?? collect();
            $loanPayments = $payments[$loan->id] ?? collect();
            $paid = $rows->filter(fn (LoanInstallment $i): bool => $i->payment_id !== null);
            $unpaid = $rows->filter(fn (LoanInstallment $i): bool => $i->payment_id === null);

            if ($rows->isEmpty()) {
                $add('error', 'CICILAN_KOSONG', $obj, 'Pinjaman tidak memiliki cicilan.');
            } else {
                if ((int) $rows->sum('pokok') !== $pokok) {
                    $add('error', 'CICILAN_JUMLAH', $obj, 'Total pokok cicilan tidak sama dengan pokok pinjaman.');
                }
                if ($rows->pluck('no')->values()->all() !== range(1, $rows->count())) {
                    $add('error', 'CICILAN_URUTAN', $obj, 'Nomor cicilan tidak berurutan.');
                }

                $dates = $rows->map(fn (LoanInstallment $i): string => $i->jatuh_tempo->toDateString())->values()->all();
                $sorted = $dates;
                sort($sorted);
                if ($dates !== $sorted) {
                    $add('error', 'CICILAN_TANGGAL', $obj, 'Jatuh tempo cicilan tidak menaik.');
                }

                if ($rows->count() === (int) $loan->tenor_bulan) {
                    foreach ($rows as $row) {
                        $expected = LoanTerms::dueDate($loan->dicairkan_pada, (int) $row->no)->toDateString();
                        if ($row->jatuh_tempo->toDateString() !== $expected) {
                            $add('error', 'CICILAN_JATUH_TEMPO', $obj, "Jatuh tempo cicilan ke-{$row->no} ({$row->jatuh_tempo->toDateString()}) tidak sama dengan pencairan + {$row->no} bulan ({$expected}).");
                        }
                    }
                }

                // Cicilan yang sudah dibayar harus berupa awalan (tidak ada yang dilewati).
                $firstUnpaidNo = $unpaid->min('no');
                if ($firstUnpaidNo !== null && $paid->contains(fn (LoanInstallment $i): bool => $i->no > $firstUnpaidNo)) {
                    $add('error', 'CICILAN_URUTAN_BAYAR', $obj, 'Ada cicilan yang dibayar mendahului cicilan sebelumnya.');
                }

                if ((int) $loan->sisa_pokok !== (int) $unpaid->sum('pokok')) {
                    $add('error', 'SISA_POKOK', $obj, 'Sisa pokok pinjaman tidak sama dengan total cicilan yang belum dibayar.');
                }

                $expectedDue = ($unpaid->first() ?? $rows->last())->jatuh_tempo->toDateString();
                if ($loan->jatuh_tempo->toDateString() !== $expectedDue) {
                    $add('error', 'JATUH_TEMPO', $obj, "Jatuh tempo {$loan->jatuh_tempo->toDateString()} tidak sama dengan jatuh tempo cicilan berikutnya ({$expectedDue}).");
                }

                if ($unpaid->isEmpty() && $loan->status !== LoanStatus::Lunas) {
                    $add('error', 'STATUS_CICILAN', $obj, 'Semua cicilan sudah dibayar tetapi pinjaman belum berstatus lunas.');
                }
                if ($unpaid->isNotEmpty() && $loan->status === LoanStatus::Lunas) {
                    $add('error', 'STATUS_CICILAN', $obj, 'Pinjaman berstatus lunas tetapi masih ada cicilan yang belum dibayar.');
                }
            }

            if ($loan->status === LoanStatus::Lunas && $loan->dilunasi_pada === null) {
                $add('error', 'LUNAS_TANPA_TANGGAL', $obj, 'Pinjaman lunas tetapi tanggal pelunasan kosong.');
            }

            // ---- Pembayaran ----
            if ($loanPayments->count() !== $paid->count()) {
                $add('error', 'PEMBAYARAN_JUMLAH', $obj, "Jumlah pembayaran ({$loanPayments->count()}) tidak sama dengan jumlah cicilan yang dibayar ({$paid->count()}).");
            }

            foreach ($paid as $row) {
                $payment = $loanPayments->firstWhere('id', $row->payment_id);

                if ($payment === null) {
                    $add('error', 'PEMBAYARAN_HILANG', $obj, "Cicilan ke-{$row->no} tertaut ke pembayaran yang tidak ditemukan.");
                } elseif ((int) $payment->pokok !== (int) $row->pokok) {
                    $add('error', 'PEMBAYARAN_POKOK', $obj, "Pokok pembayaran cicilan ke-{$row->no} tidak sama dengan pokok cicilan.");
                }
            }

            foreach ($loanPayments as $payment) {
                if ((int) $payment->denda_final > (int) $payment->denda_sistem) {
                    $add('error', 'DENDA_MELEBIHI_SISTEM', $obj, 'Denda pembayaran lebih besar daripada hitungan sistem.');
                }
            }

            // ---- Buku kas ----
            if ($out(LedgerPos::Pinjaman) !== $pokok) {
                $add('error', 'PINJAMAN_KAS', $obj, 'Entri pencairan di buku kas tidak sama dengan pokok pinjaman.');
            }
            if ($in(LedgerPos::Administrasi) !== (int) $loan->biaya_admin) {
                $add('error', 'ADMIN_KAS', $obj, 'Entri administrasi di buku kas tidak sama dengan biaya admin pinjaman.');
            }
            if (isset($byPos[LedgerPos::Koreksi->value]) || isset($byPos[LedgerPos::KoreksiAdministrasi->value])) {
                $add('error', 'KOREKSI_TANPA_HAPUS', $obj, 'Ada entri koreksi pada pinjaman yang tidak dihapus.');
            }
            if ($in(LedgerPos::PelunasanPokok) !== (int) $loanPayments->sum('pokok')) {
                $add('error', 'PELUNASAN_KAS', $obj, 'Entri pembayaran pokok di buku kas tidak sama dengan total pokok pembayaran.');
            }

            $dendaKas = $in(LedgerPos::Denda) + $in(LedgerPos::KoreksiDenda) - $out(LedgerPos::KoreksiDenda);
            if ($dendaKas !== (int) $loanPayments->sum('denda_final')) {
                $add('error', 'DENDA_KAS', $obj, 'Entri denda di buku kas tidak sama dengan total denda pembayaran.');
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

        $duplicateNik = Loan::query()
            ->join('borrowers', 'borrowers.id', '=', 'loans.borrower_id')
            ->where('loans.status', LoanStatus::Aktif)
            ->whereNotNull('borrowers.nik')
            ->select('borrowers.nik', DB::raw('COUNT(*) AS jumlah'))
            ->groupBy('borrowers.nik')
            ->having('jumlah', '>', 1)
            ->pluck('jumlah', 'nik');

        foreach ($duplicateNik as $nik => $jumlah) {
            $add('error', 'PINJAMAN_GANDA_NIK', 'NIK ' . \App\Models\Borrower::mask((string) $nik), "Memiliki {$jumlah} pinjaman aktif di seluruh cabang.");
        }

        foreach (LedgerVoid::query()->with(['entry', 'reversal'])->get() as $void) {
            $obj = "Pembatalan entri #{$void->ledger_entry_id}";
            $entry = $void->entry;
            $reversal = $void->reversal;

            if ($entry === null || $reversal === null) {
                $add('error', 'VOID_ENTRI_HILANG', $obj, 'Entri asli atau entri koreksi pembatalan tidak ditemukan.');
            } elseif ((int) $entry->jumlah !== (int) $reversal->jumlah
                || $entry->arah === $reversal->arah
                || (int) $entry->branch_id !== (int) $reversal->branch_id) {
                $add('error', 'VOID_TIDAK_SEIMBANG', $obj, 'Entri koreksi tidak membalik entri asli (jumlah, arah, atau cabang berbeda).');
            }
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
