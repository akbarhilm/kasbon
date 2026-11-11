<?php

namespace App\Models;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Buku kas bersifat append-only: entri tidak boleh diubah atau dihapus.
 * Kesalahan dikoreksi dengan entri baru (pos Koreksi).
 */
class LedgerEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pos' => LedgerPos::class,
            'arah' => Arah::class,
            'waktu' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Buku kas tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Buku kas tidak dapat dihapus.'));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // withTrashed: entri tetap menampilkan peminjam meskipun pinjamannya dihapus SA.
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class)->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
