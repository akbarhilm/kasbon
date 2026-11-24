<?php

namespace App\Filament\Resources\ModalDisetor;

use App\Enums\Role;
use App\Filament\Resources\ModalDisetor\Pages\ManageModalDisetor;
use App\Models\ModalDisetor;
use App\Support\Rupiah;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Catatan super admin tentang modal yang disetor ke tiap cabang (pengingat).
 * Tidak memengaruhi buku kas; modal resmi tetap dicatat cabang lewat Tambah modal.
 */
class ModalDisetorResource extends Resource
{
    protected static ?string $model = ModalDisetor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $modelLabel = 'Modal disetor';

    protected static ?string $pluralModelLabel = 'Modal disetor';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return auth()->user()?->role === Role::SuperAdmin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_id')->label('Cabang')->relationship('branch', 'nama')->required()->searchable()->preload(),
            DatePicker::make('tanggal')->required()->default(fn () => today()),
            TextInput::make('jumlah')
                ->label('Jumlah (Rp)')
                ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                ->stripCharacters(',')
                ->numeric()
                ->minValue(1)
                ->required(),
            TextInput::make('keterangan')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tanggal')->date('d M Y')->sortable(),
                TextColumn::make('branch.nama')->label('Cabang')->sortable()->searchable(),
                TextColumn::make('jumlah')
                    ->formatStateUsing(fn ($state) => Rupiah::format($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state) => Rupiah::format($state))),
                TextColumn::make('keterangan')->searchable(),
                TextColumn::make('user.name')->label('Dicatat oleh')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('tanggal', 'desc')
            ->filters([
                SelectFilter::make('branch_id')->label('Cabang')->relationship('branch', 'nama'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageModalDisetor::route('/')];
    }
}
