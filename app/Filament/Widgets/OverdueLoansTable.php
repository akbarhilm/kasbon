<?php

namespace App\Filament\Widgets;

use Illuminate\Database\Eloquent\Builder;

class OverdueLoansTable extends LoanListWidget
{
    protected function listHeading(): string
    {
        return 'Belum bayar (sudah lewat jatuh tempo)';
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->whereDate('jatuh_tempo', '<', today());
    }
}
