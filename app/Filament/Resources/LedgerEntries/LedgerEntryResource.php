<?php

namespace App\Filament\Resources\LedgerEntries;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Filament\Concerns\ScopesToBranch;
use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Models\LedgerEntry;
use App\Support\Rupiah;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LedgerEntryResource extends Resource
{
    use ScopesToBranch;

    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = 'Entri buku kas';

    protected static ?string $pluralModelLabel = 'Buku kas';

    protected static ?string $navigationLabel = 'Buku kas';

    protected static ?int $navigationSort = 3;

    // Buku kas append-only: tidak ada form buat, ubah, atau hapus dari tabel.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function posOf(mixed $state): LedgerPos
    {
        return $state instanceof LedgerPos ? $state : LedgerPos::from($state);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('waktu')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('branch.nama')
                    ->label('Cabang')
                    ->visible(fn (): bool => auth()->user()?->role === Role::SuperAdmin),
                TextColumn::make('pos')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::posOf($state)->label()),
                TextColumn::make('masuk')
                    ->state(fn (LedgerEntry $record): ?string => $record->arah === Arah::Masuk ? Rupiah::format($record->jumlah) : null)
                    ->alignEnd(),
                TextColumn::make('keluar')
                    ->state(fn (LedgerEntry $record): ?string => $record->arah === Arah::Keluar ? Rupiah::format($record->jumlah) : null)
                    ->alignEnd(),
                TextColumn::make('keterangan')->searchable(),
            ])
            ->defaultSort('waktu', 'desc')
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Cabang')
                    ->relationship('branch', 'nama')
                    ->visible(fn (): bool => auth()->user()?->role === Role::SuperAdmin),
                SelectFilter::make('pos')->options(
                    collect(LedgerPos::cases())->mapWithKeys(fn (LedgerPos $p): array => [$p->value => $p->label()])->all(),
                ),
                Filter::make('periode')
                    ->schema([
                        DatePicker::make('dari'),
                        DatePicker::make('sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $d) => $q->whereDate('waktu', '>=', $d))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $d) => $q->whereDate('waktu', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLedgerEntries::route('/')];
    }
}
