<?php

namespace App\Filament\Widgets;

use App\Enums\LoanStatus;
use App\Filament\Actions\LunasiAction;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Widgets\Concerns\ResolvesBranch;
use App\Models\Loan;
use App\Services\PenaltyCalculator;
use App\Support\Rupiah;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dasar untuk tiga daftar di dashboard: belum bayar, jatuh tempo hari ini, jatuh tempo 7 hari ke depan.
 */
abstract class LoanListWidget extends TableWidget
{
    use ResolvesBranch;

    protected int|string|array $columnSpan = 'full';

    abstract protected function listHeading(): string;

    abstract protected function scopeQuery(Builder $query): Builder;

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->listHeading())
            ->query(fn (): Builder => $this->scopeQuery(
                Loan::query()
                    ->with('borrower')
                    ->where('branch_id', $this->branch())
                    ->where('status', LoanStatus::Aktif),
            ))
            ->columns([
                TextColumn::make('borrower.nama')->label('Peminjam')->searchable(),
                TextColumn::make('borrower.no_hp')->label('No HP'),
                TextColumn::make('jatuh_tempo')->label('Jatuh tempo')->date('d M Y')->sortable(),
                TextColumn::make('hari_terlambat')
                    ->label('Terlambat')
                    ->state(fn (Loan $record): int => LoanResource::daysLate($record))
                    ->formatStateUsing(fn ($state): string => $state > 0 ? "{$state} hari" : '-')
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('pokok')
                    ->label('Pokok')
                    ->formatStateUsing(fn ($state) => Rupiah::format($state))
                    ->alignEnd(),
                TextColumn::make('denda_berjalan')
                    ->label('Denda berjalan')
                    ->state(fn (Loan $record): int => $this->dendaBerjalan($record))
                    ->formatStateUsing(fn ($state) => Rupiah::format($state))
                    ->alignEnd(),
                TextColumn::make('total_tagihan')
                    ->label('Total tagihan')
                    ->state(fn (Loan $record): int => $record->pokok + $this->dendaBerjalan($record))
                    ->formatStateUsing(fn ($state) => Rupiah::format($state))
                    ->alignEnd(),
            ])
            ->defaultSort('jatuh_tempo')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Tidak ada data')
            ->recordActions([
                Action::make('detail')
                    ->label('Detail')
                    ->url(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record])),
                LunasiAction::make(),
            ]);
    }

    private function dendaBerjalan(Loan $loan): int
    {
        return PenaltyCalculator::amount(
            $loan->biaya_admin,
            PenaltyCalculator::periods($loan->jatuh_tempo, now()),
        );
    }
}
