<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan super admin: modal yang disetor ke cabang. Tidak memengaruhi buku kas. */
class ModalDisetor extends Model
{
    protected $table = 'modal_disetor';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
