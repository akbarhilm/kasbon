<?php

namespace App\Filament\Widgets;

use Illuminate\Database\Eloquent\Builder;

class DueNextSevenDaysTable extends LoanListWidget
{
    protected function listHeading(): string
    {
        return 'Jatuh tempo 7 hari ke depan';
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query
            ->whereDate('jatuh_tempo', '>', today())
            ->whereDate('jatuh_tempo', '<=', today()->addDays(7));
    }
}
