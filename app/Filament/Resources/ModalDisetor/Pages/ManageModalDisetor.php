<?php

namespace App\Filament\Resources\ModalDisetor\Pages;

use App\Filament\Resources\ModalDisetor\ModalDisetorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageModalDisetor extends ManageRecords
{
    protected static string $resource = ModalDisetorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(
                fn (array $data): array => [...$data, 'created_by' => auth()->id()],
            ),
        ];
    }
}
