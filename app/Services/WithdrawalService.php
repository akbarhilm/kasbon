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
 * Pengeluaran saldo oleh cabang:
 *  - penarikan dari salah satu pos: saldo kas atau administrasi;
 *  - biaya admin bank (mis. biaya transfer ke rekening lain), dikurangkan dari saldo kas.
 * Batas = seluruh saldo pos itu (tanpa batas periode), dan tidak boleh melebihi saldo akhir cabang.
 */
final class WithdrawalService
{
    public const KAS = 'kas';
    public const ADMINISTRASI = 'administrasi';

    public function __construct(private LedgerService $ledger, private AuditLogger $audit)
    {
    }

    /** Jumlah maksimal yang boleh dikeluarkan dari pos tersebut. */
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
        $this->assertBranchUser($by);

        if (! in_array($source, [self::KAS, self::ADMINISTRASI], true)) {
            throw ValidationException::withMessages(['pos' => 'Pos penarikan tidak valid.']);
        }

        $pos = $source === self::ADMINISTRASI ? LedgerPos::PenarikanAdministrasi : LedgerPos::PenarikanKas;

        return $this->spend($by, $source, $pos, $jumlah, $waktu, $keterangan, 'penarikan.dicatat');
    }

    /** Biaya admin bank: pengeluaran dari saldo kas, tidak boleh melebihi saldo kas. */
    public function bankFee(User $by, int $jumlah, CarbonInterface $waktu, ?string $keterangan = null): LedgerEntry
    {
        $this->assertBranchUser($by);

        return $this->spend($by, self::KAS, LedgerPos::BiayaAdminBank, $jumlah, $waktu, $keterangan, 'biaya_bank.dicatat');
    }

    private function assertBranchUser(User $by): void
    {
        if ($by->role !== Role::Cabang || ! $by->branch_id) {
            throw new AuthorizationException('Hanya user cabang yang dapat mencatat pengeluaran saldo.');
        }
    }

    private function spend(
        User $by,
        string $source,
        LedgerPos $pos,
        int $jumlah,
        CarbonInterface $waktu,
        ?string $keterangan,
        string $auditAction,
    ): LedgerEntry {
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah harus lebih dari nol.']);
        }

        $branchId = (int) $by->branch_id;
        $label = $source === self::ADMINISTRASI ? 'saldo administrasi' : 'saldo kas';

        return DB::transaction(function () use ($by, $branchId, $source, $pos, $jumlah, $waktu, $keterangan, $label, $auditAction): LedgerEntry {
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

            $this->audit->record($auditAction, $entry, $branchId, $keterangan, null, [
                'pos' => $pos->value,
                'jumlah' => $jumlah,
                'batas_tersedia' => $limit,
            ], $by);

            return $entry;
        });
    }
}
