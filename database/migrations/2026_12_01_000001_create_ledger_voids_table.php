<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pembatalan entri buku kas dicatat terpisah: entri asli tidak diubah (buku kas append-only),
        // dan dibalik oleh entri koreksi yang tertaut di sini.
        Schema::create('ledger_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_entry_id')->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('reversal_entry_id')->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('voided_by')->constrained('users')->restrictOnDelete();
            $table->text('alasan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_voids');
    }
};
