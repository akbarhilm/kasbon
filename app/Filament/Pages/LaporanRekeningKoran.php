<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Branch;
use App\Services\StatementService;
use App\Support\ReportParams;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Laporan rekening koran. Filter memakai form GET biasa (tanpa Livewire),
 * sehingga tautan Excel dan cetak memakai parameter yang sama.
 */
class LaporanRekeningKoran extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan rekening koran';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.laporan-rekening-koran';

    protected function getViewData(): array
    {
        $user = auth()->user();
        $params = ReportParams::resolve(request(), $user);
        $isSuperAdmin = $user->role === Role::SuperAdmin;

        return [
            'params' => $params,
            'statement' => app(StatementService::class)->build($params->branchId, $params->from, $params->to),
            'isSuperAdmin' => $isSuperAdmin,
            'branches' => $isSuperAdmin ? Branch::query()->orderBy('nama')->pluck('nama', 'id') : collect(),
            'excelUrl' => route('laporan.rekening-koran.excel', $params->query()),
            'printUrl' => route('laporan.rekening-koran.cetak', $params->query()),
        ];
    }
}
