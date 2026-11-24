<?php

namespace App\Models;

use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use SoftDeletes; // hanya dipakai oleh penghapusan SA (lihat LoanPolicy)

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'dicairkan_pada' => 'datetime',
            'dilunasi_pada' => 'datetime',
            'jatuh_tempo' => 'date',
            'persen_admin' => 'decimal:2',
            'foto' => 'array',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('no');
    }

    /** Cicilan berikutnya yang belum dibayar. */
    public function nextInstallment(): HasOne
    {
        return $this->hasOne(LoanInstallment::class)->ofMany(['no' => 'min'], function ($query) {
            $query->whereNull('payment_id');
        });
    }
}
