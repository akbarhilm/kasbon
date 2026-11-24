<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstallment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['jatuh_tempo' => 'date'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class)->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_id !== null;
    }
}
