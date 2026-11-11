<?php

namespace App\Filament\Resources\Borrowers;

use App\Enums\Role;
use App\Filament\Concerns\ScopesToBranch;
use App\Filament\Resources\Borrowers\Pages\ManageBorrowers;
use App\Models\Borrower;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BorrowerResource extends Resource
{
    use ScopesToBranch;

    protected static ?string $model = Borrower::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'Peminjam';

    protected static ?string $pluralModelLabel = 'Peminjam';

    protected static ?int $navigationSort = 2;

    // Super admin tidak terlibat di modul peminjam.
    public static function canViewAny(): bool
    {
        return auth()->user()?->role === Role::Cabang;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nama')->required()->maxLength(255),
            TextInput::make('nama_pt')->label('Nama PT')->maxLength(255),
            TextInput::make('no_rekening')->label('No rekening')->maxLength(50),
            TextInput::make('no_hp')->label('No HP')->tel()->required()->maxLength(30),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')->searchable()->sortable(),
                TextColumn::make('nama_pt')->label('Nama PT')->searchable(),
                TextColumn::make('no_rekening')->label('No rekening')->searchable(),
                TextColumn::make('no_hp')->label('No HP')->searchable(),
            ])
            ->defaultSort('nama')
            ->recordActions([
                EditAction::make(),
                // Peminjam yang pernah punya pinjaman tidak dapat dihapus.
                DeleteAction::make()->hidden(fn (Borrower $record): bool => $record->loans()->withTrashed()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBorrowers::route('/')];
    }
}
