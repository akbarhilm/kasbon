<?php

namespace App\Filament\Resources\AuditLogs;

use App\Enums\Role;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Audit log';

    protected static ?string $pluralModelLabel = 'Audit log';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?int $navigationSort = 12;

    public static function canViewAny(): bool
    {
        return auth()->user()?->role === Role::SuperAdmin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Oleh')->placeholder('Sistem'),
                TextColumn::make('aksi')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AuditLog::label($state)),
                TextColumn::make('branch.nama')->label('Cabang')->placeholder('-'),
                TextColumn::make('objek')
                    ->state(fn (AuditLog $record): string => $record->objek_type ? "{$record->objek_type} #{$record->objek_id}" : '-'),
                TextColumn::make('keterangan')->limit(60)->tooltip(fn (AuditLog $record): ?string => $record->keterangan),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('aksi')->options(AuditLog::ACTIONS),
                SelectFilter::make('branch_id')->label('Cabang')->relationship('branch', 'nama'),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label('Detail')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading('Detail audit log')
                    ->modalContent(fn (AuditLog $record) => view('filament.audit-detail', ['log' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
