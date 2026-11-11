<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class LoanTerms
{
    /** Jatuh tempo = tanggal pencairan + tenor. Akhir bulan tidak meluap (31 Jan + 1 bulan = 28/29 Feb). */
    public static function dueDate(CarbonInterface $disbursedAt, int $tenorMonths): CarbonImmutable
    {
        return CarbonImmutable::instance($disbursedAt)
            ->setTimezone(config('pinjaman.timezone'))
            ->startOfDay()
            ->addMonthsNoOverflow($tenorMonths);
    }

    /** Biaya admin dalam rupiah bulat (pembulatan ke terdekat). */
    public static function adminFee(int $pokok, float|int|string $percent): int
    {
        return (int) round($pokok * (float) $percent / 100);
    }
}
