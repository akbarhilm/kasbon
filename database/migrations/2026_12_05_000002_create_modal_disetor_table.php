<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catatan super admin tentang modal yang disetor ke tiap cabang (pengingat).
        // TIDAK memengaruhi buku kas; modal resmi tetap dicatat cabang lewat Tambah modal.
        Schema::create('modal_disetor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('tanggal');
            $table->unsignedBigInteger('jumlah');
            $table->string('keterangan')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modal_disetor');
    }
};
