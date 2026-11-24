<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Enums\Role;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    /**
     * Membayar cicilan berikutnya yang belum dibayar, dicatat oleh user cabang pemilik pinjaman.
     * Cicilan dibayar berurutan dan harus penuh (tidak ada pembayaran di bawah nominal cicilan).
     * Denda dihitung sistem per cicilan dari jatuh tempo cicilan itu sendiri (aturan denda tidak berubah).
     * Dispensasi atau perubahan denda hanya oleh super admin setelah pembayaran (DendaAdjustmentService).
     * Untuk tenor 1 bulan ini sama dengan pelunasan.
     */
    public function payInstallment(Loan $loan, CarbonInterface $paidAt, User $by): Payment
    {
        if ($by->role !== Role::Cabang || (int) $by->branch_id !== (int) $loan->branch_id) {
            throw new AuthorizationException('Pembayaran hanya dapat dicatat oleh user cabang pemilik pinjaman.');
        }

        return DB::transaction(function () use ($loan, $paidAt, $by) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->status !== LoanStatus::Aktif) {
                throw ValidationException::withMessages(['loan' => 'Pinjaman sudah lunas.']);
            }

            $installment = $loan->installments()->whereNull('payment_id')->orderBy('no')->lockForUpdate()->first();

            if ($installment === null) {
                throw ValidationException::withMessages(['loan' => 'Tidak ada cicilan yang belum dibayar.']);
            }
            if ($paidAt->lt($loan->dicairkan_pada)) {
                throw ValidationException::withMessages(['dibayar_pada' => 'Tanggal bayar tidak boleh sebelum pencairan.']);
            }

            $periods = PenaltyCalculator::periods($installment->jatuh_tempo, $paidAt);
            $denda = PenaltyCalculator::amount($loan->biaya_admin, $periods);
            $total = $loan->installments()->count();

            $payment = Payment::create([
                'loan_id' => $loan->id,
                'dibayar_pada' => $paidAt,
                'pokok' => $installment->pokok,
                'denda_sistem' => $denda,
                'denda_final' => $denda,
                'alasan_penyesuaian' => null,
                'created_by' => $by->id,
            ]);

            $installment->update(['payment_id' => $payment->id]);

            $keterangan = $total > 1 ? "Pembayaran cicilan ke-{$installment->no} dari {$total}" : 'Pelunasan pokok';

            $this->ledger->post($loan->branch_id, LedgerPos::PelunasanPokok, Arah::Masuk, $installment->pokok, $paidAt, $loan->id, $payment->id, $keterangan, $by->id);

            if ($denda > 0) {
                $this->ledger->post($loan->branch_id, LedgerPos::Denda, Arah::Masuk, $denda, $paidAt, $loan->id, $payment->id, "Denda keterlambatan cicilan ke-{$installment->no}", $by->id);
            }

            $next = $loan->installments()->whereNull('payment_id')->orderBy('no')->first();

            if ($next !== null) {
                $loan->update([
                    'sisa_pokok' => $loan->sisa_pokok - $installment->pokok,
                    'jatuh_tempo' => $next->jatuh_tempo,
                ]);
            } else {
                $loan->update([
                    'sisa_pokok' => 0,
                    'status' => LoanStatus::Lunas,
                    'dilunasi_pada' => $paidAt,
                    'active_borrower_id' => null,
                    'active_nik' => null,
                ]);
            }

            $this->audit->record('pembayaran.dicatat', $payment, $loan->branch_id, null, null, [
                'loan_id' => $loan->id,
                'cicilan_ke' => $installment->no,
                'dari' => $total,
                'pokok' => $installment->pokok,
                'denda' => $denda,
                'dibayar_pada' => $paidAt->format('Y-m-d H:i'),
                'lunas' => $next === null,
            ], $by);

            return $payment;
        });
    }

    /** Nama lama: sama dengan payInstallment() (pada tenor 1 bulan berarti pelunasan). */
    public function settle(Loan $loan, CarbonInterface $paidAt, User $by): Payment
    {
        return $this->payInstallment($loan, $paidAt, $by);
    }
}
