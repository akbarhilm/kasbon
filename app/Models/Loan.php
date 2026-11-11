<?php

namespace App\Models;

use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
