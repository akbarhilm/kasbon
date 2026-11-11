<?php

use App\Http\Controllers\RekeningKoranController;
use Illuminate\Support\Facades\Route;

// Tambahkan di akhir routes/web.php:  require __DIR__ . '/laporan.php';
// throttle: maksimal 30 permintaan per menit per user/IP (melindungi export dari penyalahgunaan).
Route::middleware(['web', 'auth', 'throttle:30,1'])->prefix('laporan')->group(function () {
    Route::get('rekening-koran/excel', [RekeningKoranController::class, 'excel'])->name('laporan.rekening-koran.excel');
    Route::get('rekening-koran/cetak', [RekeningKoranController::class, 'print'])->name('laporan.rekening-koran.cetak');
});
