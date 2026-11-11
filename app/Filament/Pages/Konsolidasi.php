<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Branch;
use App\Support\Rupiah;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Konsolidasi seluruh cabang untuk super admin: satu baris per cabang dengan baris total,
 * dan tombol untuk membuka dashboard cabang (hanya baca).
 */
class Konsolidasi extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Konsolidasi';

    protected static ?string $title = 'Konsolidasi cabang';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.konsolidasi';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === Role::SuperAdmin;
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function period(): array
    {
        $filter = $this->tableFilters['periode'] ?? [];

        return [
            Carbon::parse($filter['dari'] ?? now()->startOfMonth())->startOfDay(),
            Carbon::parse($filter['sampai'] ?? today())->endOfDay(),
        ];
    }

    private function consolidatedQuery(): Builder
    {
        [$from, $to] = $this->period();
        $today = today()->toDateString();

        $active = "ln.branch_id = branches.id and ln.status = 'aktif' and ln.deleted_at is null";

        return Branch::query()
            ->select('branches.*')
            ->selectRaw("(select coalesce(sum(case when l.arah = 'masuk' then l.jumlah else -l.jumlah end), 0) from ledger_entries l where l.branch_id = branches.id) as saldo")
            ->selectRaw("(select coalesce(sum(case when l.arah = 'masuk' then l.jumlah else -l.jumlah end), 0) from ledger_entries l where l.branch_id = branches.id and l.pos in ('administrasi', 'penarikan_administrasi', 'koreksi_administrasi')) as saldo_administrasi")
            ->selectRaw("(select coalesce(sum(ln.pokok), 0) from loans ln where {$active}) as baki_pinjaman")
            ->selectRaw("(select coalesce(sum(ln.pokok), 0) from loans ln where {$active} and ln.jatuh_tempo < ?) as baki_macet", [$today])
            ->selectRaw("(select count(*) from loans ln where {$active} and ln.jatuh_tempo < ?) as jumlah_menunggak", [$today])
            ->selectRaw("(select coalesce(sum(l.jumlah), 0) from ledger_entries l where l.branch_id = branches.id and l.pos = 'administrasi' and l.arah = 'masuk' and l.waktu between ? and ?) as pendapatan_administrasi", [$from, $to])
            ->selectRaw("(select coalesce(sum(l.jumlah), 0) from ledger_entries l where l.branch_id = branches.id and l.pos = 'penambahan_modal' and l.arah = 'masuk' and l.waktu between ? and ?) as penambahan_modal", [$from, $to]);
    }

    public function table(Table $table): Table
    {
        $rupiah = fn ($state): string => Rupiah::format($state);

        return $table
            ->query(fn (): Builder => $this->consolidatedQuery())
            ->columns([
                TextColumn::make('nama')->label('Cabang')->searchable(),
                TextColumn::make('saldo')->label('Saldo akhir')->formatStateUsing($rupiah)->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing($rupiah)),
                TextColumn::make('saldo_kas')
                    ->label('Saldo kas')
                    ->state(fn (Branch $record): int => (int) $record->saldo - (int) $record->saldo_administrasi)
                    ->formatStateUsing($rupiah)
                    ->alignEnd(),
                TextColumn::make('saldo_administrasi')->label('Saldo administrasi')->formatStateUsing($rupiah)->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing($rupiah)),
                TextColumn::make('baki_pinjaman')->label('Baki pinjaman')->formatStateUsing($rupiah)->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing($rupiah)),
                TextColumn::make('baki_macet')->label('Baki macet')->formatStateUsing($rupiah)->alignEnd()->sortable()
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->summarize(Sum::make()->label('Total')->formatStateUsing($rupiah)),
                TextColumn::make('persen_macet')
                    ->label('% macet')
                    ->state(fn (Branch $record): string => (int) $record->baki_pinjaman > 0
                        ? number_format($record->baki_macet / $record->baki_pinjaman * 100, 1, ',', '.') . '%'
                        : '-')
                    ->alignEnd(),
                TextColumn::make('jumlah_menunggak')->label('Belum bayar')->suffix(' pinjaman')->alignEnd()->sortable(),
                TextColumn::make('pendapatan_administrasi')->label('Pendapatan administrasi')->formatStateUsing($rupiah)->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing($rupiah)),
                TextColumn::make('penambahan_modal')->label('Penambahan modal')->formatStateUsing($rupiah)->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing($rupiah)),
            ])
            ->defaultSort('baki_macet', 'desc')
            ->filters([
                Filter::make('periode')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('dari')->label('Dari')->default(now()->startOfMonth()),
                        DatePicker::make('sampai')->label('Sampai')->default(today()),
                    ])
                    ->query(fn (Builder $query): Builder => $query), // periode dipakai langsung di consolidatedQuery()
            ])
            ->recordActions([
                Action::make('buka')
                    ->label('Buka dashboard')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Branch $record): string => Dashboard::getUrl(['cabang' => $record->id])),
            ])
            ->paginated(false);
    }
}
