<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    /**
     * @param array<string, mixed>|null $sebelum
     * @param array<string, mixed>|null $sesudah
     */
    public function record(
        string $aksi,
        ?Model $objek = null,
        ?int $branchId = null,
        ?string $keterangan = null,
        ?array $sebelum = null,
        ?array $sesudah = null,
        ?User $by = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $by?->id ?? auth()->id(),
            'branch_id' => $branchId ?? $objek?->getAttribute('branch_id'),
            'aksi' => $aksi,
            'objek_type' => $objek ? class_basename($objek) : null,
            'objek_id' => $objek?->getKey(),
            'keterangan' => $keterangan,
            'data_sebelum' => $sebelum,
            'data_sesudah' => $sesudah,
        ]);
    }
}
