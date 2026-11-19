<?php

namespace App\Models;

use App\Services\MemberNumberService;
use App\Services\NikGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Borrower extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // Validasi level 1 (peminjam): NIK yang masih punya pinjaman aktif di cabang mana pun
        // tidak boleh didaftarkan (atau diubah menjadi) peminjam baru. Pengaman di sisi model;
        // form Filament memakai aturan yang sama agar pesan tampil di isian NIK.
        static::saving(function (Borrower $borrower): void {
            if (blank($borrower->nik)) {
                return;
            }
            if ($borrower->exists && ! $borrower->isDirty('nik')) {
                return;
            }

            $loan = app(NikGuard::class)->activeLoanFor((string) $borrower->nik, $borrower->exists ? $borrower->id : null);

            if ($loan !== null) {
                throw ValidationException::withMessages(['nik' => NikGuard::registrationMessage($loan)]);
            }
        });

        // Nomor anggota otomatis per cabang: XNNNN (X = huruf cabang, NNNN = urutan).
        static::creating(function (Borrower $borrower): void {
            if (blank($borrower->nomor_anggota)) {
                $borrower->nomor_anggota = app(MemberNumberService::class)->next((int) $borrower->branch_id);
            }
        });
    }

    public static function mask(?string $nik): ?string
    {
        if (blank($nik)) {
            return null;
        }

        return substr($nik, 0, 6) . str_repeat('*', max(0, strlen($nik) - 10)) . substr($nik, -4);
    }

    public function maskedNik(): ?string
    {
        return self::mask($this->nik);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
