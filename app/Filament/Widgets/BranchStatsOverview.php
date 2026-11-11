<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesBranch;
use App\Services\DashboardMetrics;
use App\Support\Rupiah;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BranchStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use ResolvesBranch;

    protected function getStats(): array
    {
        $branch = $this->branch();
        $metrics = app(DashboardMetrics::class);

        $from = Carbon::parse($this->pageFilters['dari'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->pageFilters['sampai'] ?? today())->endOfDay();
        $periode = $from->format('d M') . ' - ' . $to->format('d M Y');

        $kas = $metrics->saldoKas($branch);
        $administrasi = $metrics->saldoAdministrasi($branch);
        $ditarik = $metrics->penarikanAdministrasi($branch, $from, $to);
        $macet = $metrics->bakiMacet($branch);

        return [
            Stat::make('Saldo akhir', Rupiah::format($metrics->saldo($branch)))
                ->description('Kas ' . Rupiah::format($kas) . ' | Administrasi ' . Rupiah::format($administrasi)),
            Stat::make('Saldo kas', Rupiah::format($kas)),
            Stat::make('Saldo administrasi', Rupiah::format($administrasi))
                ->description('belum ditarik'),
            Stat::make('Baki pinjaman', Rupiah::format($metrics->bakiPinjaman($branch)))
                ->description($metrics->jumlahPinjamanAktif($branch) . ' pinjaman aktif'),
            Stat::make('Pendapatan administrasi', Rupiah::format($metrics->pendapatanAdministrasi($branch, $from, $to)))
                ->description($periode . ' | ditarik ' . Rupiah::format($ditarik)),
            Stat::make('Penambahan modal', Rupiah::format($metrics->penambahanModal($branch, $from, $to)))
                ->description($periode),
            Stat::make('Baki macet', Rupiah::format($macet['jumlah']))
                ->description($macet['count'] . ' pinjaman belum bayar')
                ->color($macet['count'] > 0 ? 'danger' : 'success'),
        ];
    }
}
