<?php

namespace App\Services;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Rupiah;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penarikan saldo oleh cabang dari salah satu pos: saldo kas atau administrasi.
 * Batas = seluruh saldo pos itu (tanpa batas periode), dan tidak boleh melebihi saldo akhir cabang.
 */
final class WithdrawalService
{
    public const KAS = 'kas';
    public const ADMINISTRASI = 'administrasi';

    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    /** Jumlah maksimal yang boleh ditarik dari pos tersebut. */
    public function limit(int $branchId, string $source): int
    {
        $total = $this->ledger->balance($branchId);
        $pool = $source === self::ADMINISTRASI
            ? $this->ledger->adminBalance($branchId)
            : $this->ledger->cashBalance($branchId);

        return max(0, min($pool, $total));
    }

    public function withdraw(User $by, string $source, int $jumlah, CarbonInterface $waktu, ?string $keterangan = null): LedgerEntry
    {
        if ($by->role !== Role::Cabang || ! $by->branch_id) {
            throw new AuthorizationException('Hanya user cabang yang dapat menarik saldo.');
        }
        if (! in_array($source, [self::KAS, self::ADMINISTRASI], true)) {
            throw ValidationException::withMessages(['pos' => 'Pos penarikan tidak valid.']);
        }
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah penarikan harus lebih dari nol.']);
        }

        $branchId = (int) $by->branch_id;
        $pos = $source === self::ADMINISTRASI ? LedgerPos::PenarikanAdministrasi : LedgerPos::PenarikanKas;
        $label = $source === self::ADMINISTRASI ? 'saldo administrasi' : 'saldo kas';

        return DB::transaction(function () use ($by, $branchId, $source, $jumlah, $waktu, $keterangan, $pos, $label): LedgerEntry {
            // Lock cabang agar pengecekan batas dan penulisan tidak saling menyalip.
            Branch::query()->lockForUpdate()->findOrFail($branchId);

            $limit = $this->limit($branchId, $source);

            if ($jumlah > $limit) {
                throw ValidationException::withMessages([
                    'jumlah' => sprintf('Jumlah melebihi %s yang tersedia (%s).', $label, Rupiah::format($limit)),
                ]);
            }

            $entry = $this->ledger->post(
                $branchId,
                $pos,
                Arah::Keluar,
                $jumlah,
                $waktu,
                null,
                null,
                filled($keterangan) ? $keterangan : $pos->label(),
                $by->id,
            );

            $this->audit->record('penarikan.dicatat', $entry, $branchId, $keterangan, null, [
                'pos' => $pos->value,
                'jumlah' => $jumlah,
                'batas_tersedia' => $limit,
            ], $by);

            return $entry;
        });
    }
}
