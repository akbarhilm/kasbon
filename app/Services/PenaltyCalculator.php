<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class PenaltyCalculator
{
    /**
     * Jumlah periode jatuh tempo yang sudah terlewati pada tanggal bayar.
     * Lewat 1 hari = 1, lewat periode jatuh tempo berikutnya = 2, dan seterusnya.
     * Periode jatuh tempo ke-n = jatuh tempo + (n-1) x panjang periode.
     */
    public static function periods(CarbonInterface $dueDate, CarbonInterface $paidAt): int
    {
        $tz = config('pinjaman.timezone');
        $step = max(1, (int) config('pinjaman.penalty_period_months', 1));

        $due = CarbonImmutable::createFromFormat('Y-m-d', $dueDate->format('Y-m-d'), $tz)->startOfDay();
        $paid = CarbonImmutable::instance($paidAt)->setTimezone($tz)->startOfDay();

        $k = 0;
        while ($paid->greaterThan($due->addMonthsNoOverflow($k * $step))) {
            $k++;
        }

        return $k;
    }

    /** Denda = jumlah periode x biaya admin. */
    public static function amount(int $adminFee, int $periods): int
    {
        return $adminFee * $periods;
    }
}
