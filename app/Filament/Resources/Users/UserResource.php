<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        return auth()->user()?->role === Role::SuperAdmin;
    }

    public static function form(Schema $schema): Schema
    {
        $isCabang = fn (Get $get): bool => $get('role') === Role::Cabang->value;

        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique()->maxLength(255),
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText('Kosongkan saat edit jika password tidak diubah. Disimpan ter-hash.'),
            Select::make('role')
                ->label('Peran')
                ->options([Role::Cabang->value => 'Cabang', Role::SuperAdmin->value => 'Super admin'])
                ->required()
                ->live(),
            Select::make('branch_id')
                ->label('Cabang')
                ->relationship('branch', 'nama')
                ->visible($isCabang)
                ->required($isCabang),
            Toggle::make('aktif')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ($state instanceof Role ? $state : Role::from($state)) === Role::SuperAdmin ? 'Super admin' : 'Cabang'),
                TextColumn::make('branch.nama')->label('Cabang'),
                IconColumn::make('aktif')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
