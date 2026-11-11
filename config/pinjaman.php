<?php

return [
    // Semua perhitungan tanggal jatuh tempo dan denda memakai satu zona waktu acuan.
    'timezone' => 'Asia/Jakarta',

    // Tenor maksimal (bulan). Divalidasi di service dan di database.
    'max_tenor_months' => 3,

    // Biaya admin bawaan (persen dari pokok), bisa diubah user per pinjaman.
    'default_admin_percent' => 10,

    // Panjang satu periode denda (bulan). Asumsi: 1 bulan. Konfirmasi ke client.
    'penalty_period_months' => 1,

    // Backup database (php artisan pinjaman:backup).
    'backup' => [
        // Kosong = storage/app/backups. Disarankan folder di luar aplikasi, mis. /var/backups/pinjaman
        'path' => env('BACKUP_PATH'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    ],
];
