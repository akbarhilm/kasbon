<?php

namespace App\Filament\Resources\Loans;

use App\Enums\LoanStatus;
use App\Enums\Role;
use App\Filament\Actions\DeleteLoanAction;
use App\Filament\Actions\LunasiAction;
use App\Filament\Concerns\ScopesToBranch;
use App\Filament\Resources\Loans\Pages\CreateLoan;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\LoanTerms;
use App\Services\PenaltyCalculator;
use App\Support\Rupiah;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoanResource extends Resource
{
    use ScopesToBranch;

    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'Pinjaman';

    protected static ?string $pluralModelLabel = 'Pinjaman';

    protected static ?int $navigationSort = 1;

    public static function statusOf(mixed $state): LoanStatus
    {
        return $state instanceof LoanStatus ? $state : LoanStatus::from($state);
    }

    public static function daysLate(Loan $loan): int
    {
        if ($loan->status !== LoanStatus::Aktif) {
            return 0;
        }

        return max(0, (int) $loan->jatuh_tempo->copy()->startOfDay()->diffInDays(now()->startOfDay(), false));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('borrower_id')
                ->label('Peminjam')
                ->required()
                ->searchable()
                ->options(fn (): array => Borrower::query()
                    ->where('branch_id', auth()->user()->branch_id)
                    ->whereDoesntHave('loans', fn (Builder $q) => $q->where('status', LoanStatus::Aktif))
                    ->orderBy('nama')
                    ->pluck('nama', 'id')
                    ->all())
                ->helperText('Hanya peminjam tanpa pinjaman aktif yang ditampilkan.')
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    $borrower = Borrower::query()->find($state);
                    $set('no_hp', $borrower?->no_hp);
                    $set('no_rekening', $borrower?->no_rekening);
                }),
            TextInput::make('no_hp')
                ->label('No HP peminjam')
                ->tel()
                ->required()
                ->maxLength(30)
                ->helperText('Boleh diperbarui di sini; perubahan tercatat di audit log.'),
            TextInput::make('no_rekening')
                ->label('No rekening peminjam')
                ->maxLength(50),
            TextInput::make('pokok')
                ->label('Jumlah pinjaman')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->prefix('Rp')
                ->required()
                ->live(onBlur: true),
            TextInput::make('persen_admin')
                ->label('Biaya admin')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->default(fn () => config('pinjaman.default_admin_percent'))
                ->required()
                ->live(onBlur: true),
            DateTimePicker::make('dicairkan_pada')
                ->label('Tanggal dan jam pencairan')
                ->seconds(false)
                ->default(fn () => now())
                ->required()
                ->live(),
            Select::make('tenor_bulan')
                ->label('Tenor')
                ->options(collect(range(1, (int) config('pinjaman.max_tenor_months')))
                    ->mapWithKeys(fn (int $m): array => [$m => "{$m} bulan"])
                    ->all())
                ->required()
                ->live(),
            TextEntry::make('pratinjau')
                ->label('Pratinjau')
                ->columnSpanFull()
                ->state(function (Get $get): string {
                    $pokok = (int) $get('pokok');
                    $tenor = (int) $get('tenor_bulan');
                    $at = $get('dicairkan_pada');

                    if (! $pokok || ! $tenor || blank($at)) {
                        return 'Isi jumlah, tenor, dan tanggal pencairan.';
                    }

                    $fee = LoanTerms::adminFee($pokok, (float) $get('persen_admin'));
                    $due = LoanTerms::dueDate(Carbon::parse($at), $tenor)->translatedFormat('d M Y');

                    return 'Biaya admin ' . Rupiah::format($fee)
                        . ' | Diterima peminjam ' . Rupiah::format($pokok - $fee)
                        . ' | Jatuh tempo ' . $due;
                }),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pinjaman')->columns(3)->schema([
                TextEntry::make('borrower.nama')->label('Peminjam'),
                TextEntry::make('borrower.nama_pt')->label('Nama PT')->placeholder('-'),
                TextEntry::make('borrower.no_hp')->label('No HP'),
                TextEntry::make('borrower.no_rekening')->label('No rekening')->placeholder('-'),
                TextEntry::make('dicairkan_pada')->label('Dicairkan')->dateTime('d M Y H:i'),
                TextEntry::make('tenor_bulan')->label('Tenor')->suffix(' bulan'),
                TextEntry::make('jatuh_tempo')->label('Jatuh tempo')->date('d M Y'),
                TextEntry::make('pokok')->label('Pokok')->formatStateUsing(fn ($state) => Rupiah::format($state)),
                TextEntry::make('biaya_admin')->label('Biaya admin')->formatStateUsing(fn ($state) => Rupiah::format($state)),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(static::statusOf($state)->value))
                    ->color(fn ($state) => static::statusOf($state) === LoanStatus::Lunas ? 'success' : 'warning'),
                TextEntry::make('denda_berjalan')
                    ->label('Denda berjalan')
                    ->state(fn (Loan $record): string => $record->status === LoanStatus::Aktif
                        ? Rupiah::format(PenaltyCalculator::amount($record->biaya_admin, PenaltyCalculator::periods($record->jatuh_tempo, now())))
                        : '-'),
            ]),
            Section::make('Riwayat pembayaran')->schema([
                RepeatableEntry::make('payments')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('dibayar_pada')->label('Dibayar')->dateTime('d M Y H:i'),
                        TextEntry::make('pokok')->formatStateUsing(fn ($state) => Rupiah::format($state)),
                        TextEntry::make('denda_final')->label('Denda')->formatStateUsing(fn ($state) => Rupiah::format($state)),
                        TextEntry::make('alasan_penyesuaian')->label('Alasan')->placeholder('-'),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('borrower.nama')->label('Peminjam')->searchable()->sortable(),
                TextColumn::make('branch.nama')
                    ->label('Cabang')
                    ->visible(fn (): bool => auth()->user()?->role === Role::SuperAdmin),
                TextColumn::make('dicairkan_pada')->label('Dicairkan')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('deleted_at')
                    ->label('Dihapus')
                    ->dateTime('d M Y H:i')
                    ->badge()
                    ->color('danger')
                    ->placeholder('')
                    ->visible(fn (): bool => auth()->user()?->role === Role::SuperAdmin),
                TextColumn::make('tenor_bulan')->label('Tenor')->suffix(' bulan'),
                TextColumn::make('jatuh_tempo')->label('Jatuh tempo')->date('d M Y')->sortable(),
                TextColumn::make('pokok')
                    ->label('Pokok')
                    ->formatStateUsing(fn ($state) => Rupiah::format($state))
                    ->alignEnd(),
                TextColumn::make('hari_terlambat')
                    ->label('Terlambat')
                    ->state(fn (Loan $record): int => static::daysLate($record))
                    ->formatStateUsing(fn ($state): string => $state > 0 ? "{$state} hari" : '-')
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(static::statusOf($state)->value))
                    ->color(fn ($state) => static::statusOf($state) === LoanStatus::Lunas ? 'success' : 'warning'),
            ])
            ->defaultSort('jatuh_tempo')
            ->filters([
                SelectFilter::make('status')->options(['aktif' => 'Aktif', 'lunas' => 'Lunas']),
                TrashedFilter::make()->visible(fn (): bool => auth()->user()?->role === Role::SuperAdmin),
                Filter::make('terlambat')
                    ->label('Terlambat')
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where('status', LoanStatus::Aktif)
                        ->whereDate('jatuh_tempo', '<', today())),
                Filter::make('hari_ini')
                    ->label('Jatuh tempo hari ini')
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where('status', LoanStatus::Aktif)
                        ->whereDate('jatuh_tempo', today())),
                Filter::make('tujuh_hari')
                    ->label('Jatuh tempo 7 hari ke depan')
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where('status', LoanStatus::Aktif)
                        ->whereDate('jatuh_tempo', '>', today())
                        ->whereDate('jatuh_tempo', '<=', today()->addDays(7))),
            ])
            ->recordActions([
                ViewAction::make(),
                LunasiAction::make(),
                DeleteLoanAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoans::route('/'),
            'create' => CreateLoan::route('/create'),
            'view' => ViewLoan::route('/{record}'),
        ];
    }
}
