<?php

namespace App\Support;

final class Rupiah
{
    public static function format(int|float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) ($amount ?? 0), 0, ',', '.');
    }
}
