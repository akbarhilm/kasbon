<?php

namespace App\Filament\Resources\Borrowers;

use App\Enums\Role;
use App\Filament\Concerns\ScopesToBranch;
use App\Filament\Resources\Borrowers\Pages\ManageBorrowers;
use App\Models\Borrower;
use App\Services\NikGuard;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use App\Services\MemberNumberService;

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
            TextInput::make('nomor_anggota')
                ->label('No. anggota')
                ->default(fn (): string => app(MemberNumberService::class)->preview((int) auth()->user()->branch_id))
                ->disabled()
                ->dehydrated(false)
               ->helperText('Dibuat otomatis berdasarkan cabang Anda.'),
            TextInput::make('nik')
                ->label('NIK')
                ->required()
                ->length(16)
                ->regex('/^\d{16}$/')
                ->inputMode('numeric')
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('branch_id', auth()->user()->branch_id),
                )
                ->validationMessages([
                    'unique' => 'NIK ini sudah terdaftar di cabang Anda.',
                    'regex' => 'NIK harus 16 digit angka.',
                    'length' => 'NIK harus 16 digit angka.',
                ])
                // Validasi level 1: tolak NIK yang masih punya pinjaman aktif di cabang mana pun.
                ->rule(fn ($record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $loan = app(NikGuard::class)->activeLoanFor((string) $value, $record?->id);

                    if ($loan !== null) {
                        $fail(NikGuard::registrationMessage($loan));
                    }
                })
                ->helperText('16 digit. NIK dipakai untuk memastikan satu orang hanya punya satu pinjaman aktif di seluruh cabang.'),
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
                TextColumn::make('nomor_anggota')->label('No. anggota')->searchable()->sortable(),
                TextColumn::make('nama')->searchable()->sortable(),
                TextColumn::make('nik')
                    ->label('NIK')
                    ->formatStateUsing(fn (?string $state): string => Borrower::mask($state) ?? '-')
                    ->searchable(),
                TextColumn::make('nama_pt')->label('Nama PT')->searchable(),
                TextColumn::make('no_rekening')->label('No rekening')->searchable(),
                TextColumn::make('no_hp')->label('No HP')->searchable(),
                TextColumn::make('loans_count')->counts('loans')->label('Pinjaman (kali)')->alignEnd(),
                TextColumn::make('loans_max_dicairkan_pada')
                    ->max('loans', 'dicairkan_pada')
                    ->label('Terakhir pinjam')
                    ->dateTime('d M Y')
                    ->placeholder('-'),
            ])
            ->defaultSort('nomor_anggota')
            ->recordActions([
                Action::make('riwayat')
                    ->label('Riwayat')
                    ->icon(Heroicon::OutlinedClock)
                    ->modalHeading(fn (Borrower $record): string => 'Riwayat pinjaman: ' . $record->nama)
                    ->modalContent(fn (Borrower $record) => view('filament.borrower-history', ['borrower' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->slideOver(),
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
