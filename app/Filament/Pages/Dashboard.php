<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Widgets\BranchStatsOverview;
use App\Filament\Widgets\DueNextSevenDaysTable;
use App\Filament\Widgets\DueTodayTable;
use App\Filament\Widgets\OverdueLoansTable;
use App\Models\Branch;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Dashboard cabang. User cabang melihat cabangnya sendiri.
 * Super admin membukanya dari halaman Konsolidasi dengan ?cabang={id} (hanya baca).
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    #[Url(as: 'cabang')]
    public ?int $cabang = null;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->role === Role::SuperAdmin
            && (! $this->cabang || ! Branch::query()->whereKey($this->cabang)->exists())) {
            $this->redirect(Konsolidasi::getUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        $user = auth()->user();

        if ($user->role === Role::SuperAdmin && $this->cabang) {
            return 'Dashboard ' . (Branch::query()->find($this->cabang)?->nama ?? '');
        }

        return 'Dashboard';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kembali')
                ->label('Kembali ke konsolidasi')
                ->url(fn (): string => Konsolidasi::getUrl())
                ->visible(fn (): bool => auth()->user()?->role === Role::SuperAdmin),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Periode')
                ->description('Berlaku untuk pendapatan administrasi dan penambahan modal.')
                ->schema([
                    DatePicker::make('dari')->label('Dari')->default(now()->startOfMonth())->live(),
                    DatePicker::make('sampai')->label('Sampai')->default(today())->live(),
                ])
                ->columns(2),
        ]);
    }

    public function getWidgets(): array
    {
        $user = auth()->user();
        $branchId = $user->role === Role::Cabang ? (int) $user->branch_id : $this->cabang;

        return [
            BranchStatsOverview::make(['branchId' => $branchId]),
            OverdueLoansTable::make(['branchId' => $branchId]),
            DueTodayTable::make(['branchId' => $branchId]),
            DueNextSevenDaysTable::make(['branchId' => $branchId]),
        ];
    }
}
