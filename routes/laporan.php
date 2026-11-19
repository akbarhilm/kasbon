<?php

use App\Http\Controllers\LoanPhotoController;
use App\Http\Controllers\RekeningKoranController;
use Illuminate\Support\Facades\Route;

// Tambahkan di akhir routes/web.php:  require __DIR__ . '/laporan.php';
// throttle: melindungi export dan unduhan dari penyalahgunaan.
Route::middleware(['web', 'auth', 'throttle:30,1'])->prefix('laporan')->group(function () {
    Route::get('rekening-koran/excel', [RekeningKoranController::class, 'excel'])->name('laporan.rekening-koran.excel');
    Route::get('rekening-koran/cetak', [RekeningKoranController::class, 'print'])->name('laporan.rekening-koran.cetak');
});

Route::middleware(['web', 'auth', 'throttle:120,1'])->group(function () {
    Route::get('pinjaman/{loan}/foto/{index}', [LoanPhotoController::class, 'show'])
        ->whereNumber(['loan', 'index'])
        ->name('loans.foto');
});
