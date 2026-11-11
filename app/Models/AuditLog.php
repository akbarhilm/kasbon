<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Audit log append-only: tidak dapat diubah atau dihapus. */
class AuditLog extends Model
{
    public const ACTIONS = [
        'pinjaman.dibuat' => 'Pinjaman dibuat',
        'pinjaman.dihapus' => 'Pinjaman dihapus',
        'pembayaran.dicatat' => 'Pembayaran dicatat',
        'denda.disesuaikan' => 'Denda disesuaikan',
        'peminjam.dibuat' => 'Peminjam dibuat',
        'peminjam.diubah' => 'Peminjam diubah',
        'pengguna.dibuat' => 'Pengguna dibuat',
        'pengguna.diubah' => 'Pengguna diubah',
        'penarikan.dicatat' => 'Penarikan saldo',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_sebelum' => 'array',
            'data_sesudah' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Audit log tidak dapat dihapus.'));
    }

    public static function label(string $aksi): string
    {
        return self::ACTIONS[$aksi] ?? $aksi;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
