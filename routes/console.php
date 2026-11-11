<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Memerlukan cron: * * * * * cd /var/www/pinjaman && php artisan schedule:run >> /dev/null 2>&1

// 01:30 - periksa konsistensi data (pinjaman, pembayaran, buku kas)
Schedule::command('pinjaman:cek-integritas')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Pemeriksaan integritas data menemukan masalah. Jalankan: php artisan pinjaman:cek-integritas'));

// 02:00 - backup database
Schedule::command('pinjaman:backup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Backup database gagal. Jalankan: php artisan pinjaman:backup'));
