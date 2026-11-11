<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    /**
     * Pelunasan penuh (tidak ada pembayaran sebagian).
     * $dendaFinal null = pakai denda hitungan sistem; selain itu wajib disertai alasan.
     */
    public function settle(
        Loan $loan,
        CarbonInterface $paidAt,
        ?int $dendaFinal,
        ?string $alasan,
        User $by,
    ): Payment {
        return DB::transaction(function () use ($loan, $paidAt, $dendaFinal, $alasan, $by) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->status !== LoanStatus::Aktif) {
                throw ValidationException::withMessages(['loan' => 'Pinjaman sudah lunas.']);
            }
            if ($paidAt->lt($loan->dicairkan_pada)) {
                throw ValidationException::withMessages(['dibayar_pada' => 'Tanggal bayar tidak boleh sebelum pencairan.']);
            }

            $periods = PenaltyCalculator::periods($loan->jatuh_tempo, $paidAt);
            $dendaSistem = PenaltyCalculator::amount($loan->biaya_admin, $periods);
            $final = $dendaFinal ?? $dendaSistem;

            if ($final < 0) {
                throw ValidationException::withMessages(['denda_final' => 'Denda tidak boleh negatif.']);
            }
            if ($final !== $dendaSistem && trim((string) $alasan) === '') {
                throw ValidationException::withMessages(['alasan_penyesuaian' => 'Alasan wajib diisi jika denda diubah.']);
            }

            $payment = Payment::create([
                'loan_id' => $loan->id,
                'dibayar_pada' => $paidAt,
                'pokok' => $loan->pokok,
                'denda_sistem' => $dendaSistem,
                'denda_final' => $final,
                'alasan_penyesuaian' => $final !== $dendaSistem ? $alasan : null,
                'created_by' => $by->id,
            ]);

            $this->ledger->post($loan->branch_id, LedgerPos::PelunasanPokok, Arah::Masuk, $loan->pokok, $paidAt, $loan->id, $payment->id, 'Pelunasan pokok', $by->id);

            if ($final > 0) {
                $this->ledger->post($loan->branch_id, LedgerPos::Denda, Arah::Masuk, $final, $paidAt, $loan->id, $payment->id, 'Denda keterlambatan', $by->id);
            }

            $loan->update([
                'status' => LoanStatus::Lunas,
                'dilunasi_pada' => $paidAt,
                'active_borrower_id' => null,
            ]);

            $this->audit->record('pembayaran.dicatat', $payment, $loan->branch_id, null, null, [
                'loan_id' => $loan->id,
                'pokok' => $loan->pokok,
                'denda_sistem' => $dendaSistem,
                'denda_final' => $final,
                'dibayar_pada' => $paidAt->format('Y-m-d H:i'),
            ], $by);

            if ($final !== $dendaSistem) {
                $this->audit->record('denda.disesuaikan', $payment, $loan->branch_id, $alasan, ['denda' => $dendaSistem], ['denda' => $final], $by);
            }

            return $payment;
        });
    }
}
