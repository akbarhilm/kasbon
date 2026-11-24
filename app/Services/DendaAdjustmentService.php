<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Support\Rupiah;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penyesuaian denda (dispensasi) oleh super admin SETELAH cabang mencatat pelunasan beserta denda.
 * Nilai baru antara 0 dan denda hitungan sistem. Selisihnya dicatat sebagai entri Koreksi denda di buku kas
 * (kas cabang berkurang jika denda diturunkan, bertambah jika dikembalikan), dan masuk audit log.
 */
final class DendaAdjustmentService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    public function adjust(Payment $payment, int $dendaBaru, string $alasan, User $by): Payment
    {
        if ($by->role !== Role::SuperAdmin) {
            throw new AuthorizationException('Hanya super admin yang dapat menyesuaikan denda.');
        }

        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan penyesuaian minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($payment, $dendaBaru, $alasan, $by): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $loan = Loan::withTrashed()->findOrFail($payment->loan_id);

            if ($loan->trashed()) {
                throw ValidationException::withMessages(['loan' => 'Pinjaman sudah dihapus; denda tidak dapat disesuaikan.']);
            }

            Branch::query()->lockForUpdate()->findOrFail($loan->branch_id);

            $lama = (int) $payment->denda_final;
            $sistem = (int) $payment->denda_sistem;

            if ($dendaBaru < 0 || $dendaBaru > $sistem) {
                throw ValidationException::withMessages([
                    'denda_baru' => 'Denda harus antara Rp 0 dan ' . Rupiah::format($sistem) . ' (hitungan sistem).',
                ]);
            }
            if ($dendaBaru === $lama) {
                throw ValidationException::withMessages(['denda_baru' => 'Nilai denda sama dengan yang sekarang.']);
            }

            $selisih = $dendaBaru - $lama;

            $this->ledger->post(
                $loan->branch_id,
                LedgerPos::KoreksiDenda,
                $selisih < 0 ? Arah::Keluar : Arah::Masuk,
                abs($selisih),
                now(),
                $loan->id,
                $payment->id,
                "Penyesuaian denda pinjaman #{$loan->id}",
                $by->id,
            );

            $payment->forceFill(['denda_final' => $dendaBaru, 'alasan_penyesuaian' => $alasan])->save();

            $this->audit->record(
                'denda.disesuaikan',
                $payment,
                $loan->branch_id,
                $alasan,
                ['denda' => $lama, 'denda_sistem' => $sistem],
                ['denda' => $dendaBaru, 'perubahan_saldo' => $selisih],
                $by,
            );

            return $payment;
        });
    }
}
