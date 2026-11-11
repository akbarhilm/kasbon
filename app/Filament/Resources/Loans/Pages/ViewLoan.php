<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Actions\DeleteLoanAction;
use App\Filament\Actions\LunasiAction;
use App\Filament\Resources\Loans\LoanResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [LunasiAction::make(), DeleteLoanAction::make()];
    }
}
