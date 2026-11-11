<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('borrower_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pokok');
            $table->decimal('persen_admin', 5, 2);
            $table->unsignedBigInteger('biaya_admin');
            $table->dateTime('dicairkan_pada');
            $table->unsignedTinyInteger('tenor_bulan');
            $table->date('jatuh_tempo');
            $table->string('status')->default('aktif');
            $table->dateTime('dilunasi_pada')->nullable();

            // Terisi borrower_id selama pinjaman aktif, NULL jika lunas.
            // Unique index di sini menjamin 1 peminjam hanya punya 1 pinjaman aktif,
            // sekalipun ada dua permintaan bersamaan.
            $table->unsignedBigInteger('active_borrower_id')->nullable()->unique();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('alasan_hapus')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'jatuh_tempo']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_tenor_check CHECK (tenor_bulan BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_pokok_check CHECK (pokok > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
