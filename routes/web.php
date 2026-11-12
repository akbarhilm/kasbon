<?php

use Illuminate\Support\Facades\Route;



   Route::get('/', function () {
       return view('landing', [
           'loginUrl' => route('filament.admin.auth.login'),
           'appUrl' => Route::has('filament.admin.pages.dashboard')
               ? route('filament.admin.pages.dashboard')
               : url('/app'),
       ]);
   })->name('landing');

require __DIR__ . '/laporan.php';