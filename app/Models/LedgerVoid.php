<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Catatan pembatalan entri buku kas. Append-only: tidak dapat diubah atau dihapus. */
class LedgerVoid extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catatan pembatalan tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Catatan pembatalan tidak dapat dihapus.'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'reversal_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
