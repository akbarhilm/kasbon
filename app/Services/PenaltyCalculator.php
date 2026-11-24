<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class PenaltyCalculator
{
    /**
     * Jumlah periode denda pada tanggal bayar.
     * Batas tiap periode = jatuh tempo periode itu + masa tenggang (config pinjaman.penalty_grace_days).
     * Denda baru berlaku setelah batas terlewati. Contoh masa tenggang 3 hari, jatuh tempo tgl 17:
     * bayar sampai tgl 20 tanpa denda, tgl 21 denda 1x biaya admin; periode kedua mengikuti
     * jatuh tempo bulan berikutnya + masa tenggang. Jatuh tempo sendiri tidak berubah.
     */
    public static function periods(CarbonInterface $dueDate, CarbonInterface $paidAt): int
    {
        $tz = config('pinjaman.timezone');
        $step = max(1, (int) config('pinjaman.penalty_period_months', 1));
        $grace = max(0, (int) config('pinjaman.penalty_grace_days', 0));

        $due = CarbonImmutable::createFromFormat('Y-m-d', $dueDate->format('Y-m-d'), $tz)->startOfDay();
        $paid = CarbonImmutable::instance($paidAt)->setTimezone($tz)->startOfDay();

        $k = 0;
        while ($paid->greaterThan($due->addMonthsNoOverflow($k * $step)->addDays($grace))) {
            $k++;
        }

        return $k;
    }

    /** Tanggal pertama denda berlaku (jatuh tempo + masa tenggang + 1 hari). */
    public static function penaltyStartsOn(CarbonInterface $dueDate): CarbonImmutable
    {
        $tz = config('pinjaman.timezone');
        $grace = max(0, (int) config('pinjaman.penalty_grace_days', 0));

        return CarbonImmutable::createFromFormat('Y-m-d', $dueDate->format('Y-m-d'), $tz)
            ->startOfDay()
            ->addDays($grace + 1);
    }

    /** Denda = jumlah periode x biaya admin. */
    public static function amount(int $adminFee, int $periods): int
    {
        return $adminFee * $periods;
    }
}
