<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\LedgerVoid;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembatalan entri buku kas yang diinput manual oleh cabang, hanya oleh super admin.
 * Entri asli tidak diubah; dibalik dengan entri koreksi (arah berlawanan, kantong yang sama) dan dicatat di
 * ledger_voids serta audit log. Entri milik pinjaman dan denda dikoreksi lewat hapus pinjaman dan Sesuaikan denda.
 */
final class LedgerVoidService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    /** @return array<int, LedgerPos> */
    public static function voidablePositions(): array
    {
        return [
            LedgerPos::SaldoAwal,
            LedgerPos::PenambahanModal,
            LedgerPos::BungaBank,
            LedgerPos::Penarikan,
            LedgerPos::PenarikanKas,
            LedgerPos::PenarikanAdministrasi,
            LedgerPos::BiayaAdminBank,
        ];
    }

    public static function isVoidable(LedgerEntry $entry): bool
    {
        return in_array($entry->pos, self::voidablePositions(), true)
            && $entry->loan_id === null
            && $entry->payment_id === null;
    }

    /**
     * Dampak pembatalan terhadap saldo cabang, untuk ditampilkan sebelum super admin mengonfirmasi.
     *
     * @return array{kantong:string,perubahan:int,sekarang:int,setelah:int}
     */
    public function impact(LedgerEntry $entry): array
    {
        $administrasi = $entry->pos === LedgerPos::PenarikanAdministrasi;
        $perubahan = $entry->arah === Arah::Masuk ? -$entry->jumlah : $entry->jumlah;
        $sekarang = $administrasi
            ? $this->ledger->adminBalance($entry->branch_id)
            : $this->ledger->cashBalance($entry->branch_id);

        return [
            'kantong' => $administrasi ? 'saldo administrasi' : 'saldo kas',
            'perubahan' => $perubahan,
            'sekarang' => $sekarang,
            'setelah' => $sekarang + $perubahan,
        ];
    }

    public function void(LedgerEntry $entry, string $alasan, User $by): LedgerVoid
    {
        if ($by->role !== Role::SuperAdmin) {
            throw new AuthorizationException('Hanya super admin yang dapat membatalkan entri buku kas.');
        }

        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan pembatalan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($entry, $alasan, $by): LedgerVoid {
            Branch::query()->lockForUpdate()->findOrFail($entry->branch_id);
            $entry = LedgerEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! self::isVoidable($entry)) {
                throw ValidationException::withMessages(['entry' => 'Entri ini tidak dapat dibatalkan dari buku kas (pinjaman dan denda dikoreksi lewat halaman Pinjaman).']);
            }
            if (LedgerVoid::query()->where('ledger_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['entry' => 'Entri ini sudah dibatalkan.']);
            }

            $impact = $this->impact($entry);

            // Pembatalan penarikan administrasi harus kembali ke kantong administrasi.
            $reversal = $this->ledger->post(
                $entry->branch_id,
                $entry->pos === LedgerPos::PenarikanAdministrasi ? LedgerPos::KoreksiAdministrasi : LedgerPos::Koreksi,
                $entry->arah === Arah::Masuk ? Arah::Keluar : Arah::Masuk,
                $entry->jumlah,
                now(),
                null,
                null,
                "Pembatalan {$entry->pos->label()} #{$entry->id}",
                $by->id,
            );

            $void = LedgerVoid::create([
                'ledger_entry_id' => $entry->id,
                'reversal_entry_id' => $reversal->id,
                'voided_by' => $by->id,
                'alasan' => $alasan,
            ]);

            $this->audit->record(
                'entri_kas.dibatalkan',
                $entry,
                $entry->branch_id,
                $alasan,
                [
                    'pos' => $entry->pos->value,
                    'arah' => $entry->arah->value,
                    'jumlah' => $entry->jumlah,
                    'waktu' => $entry->waktu->format('Y-m-d H:i'),
                    'keterangan' => $entry->keterangan,
                    'diinput_oleh' => $entry->created_by,
                ],
                ['entri_koreksi' => $reversal->id, 'perubahan_' . str_replace(' ', '_', $impact['kantong']) => $impact['perubahan']],
                $by,
            );

            return $void;
        });
    }
}
