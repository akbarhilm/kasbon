<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['dibayar_pada' => 'datetime'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** Cicilan yang dibayar oleh pembayaran ini. */
    public function installment(): HasOne
    {
        return $this->hasOne(LoanInstallment::class, 'payment_id');
    }
}
