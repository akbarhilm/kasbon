<?php

namespace App\Filament\Widgets;

use Illuminate\Database\Eloquent\Builder;

class DueTodayTable extends LoanListWidget
{
    protected function listHeading(): string
    {
        return 'Jatuh tempo hari ini';
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->whereDate('jatuh_tempo', today());
    }
}
