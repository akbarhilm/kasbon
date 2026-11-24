<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Sisa pokok yang belum dibayar (baki). Berkurang setiap cicilan dibayar.
            $table->unsignedBigInteger('sisa_pokok')->default(0)->after('pokok');
        });

        // Tenor 1 bulan = 1 pembayaran; tenor 2 dan 3 bulan = cicilan pokok per bulan.
        // loans.jatuh_tempo menunjuk jatuh tempo cicilan berikutnya yang belum dibayar.
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('no');
            $table->date('jatuh_tempo');
            $table->unsignedBigInteger('pokok');
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'no']);
        });

        // Pinjaman yang sudah ada dijadikan satu cicilan (pembayaran sekaligus) sesuai jatuh tempo lamanya.
        foreach (DB::table('loans')->orderBy('id')->get(['id', 'pokok', 'jatuh_tempo']) as $loan) {
            $paymentId = DB::table('payments')->where('loan_id', $loan->id)->orderBy('id')->value('id');

            DB::table('loan_installments')->insert([
                'loan_id' => $loan->id,
                'no' => 1,
                'jatuh_tempo' => $loan->jatuh_tempo,
                'pokok' => $loan->pokok,
                'payment_id' => $paymentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('loans')->where('id', $loan->id)->update([
                'sisa_pokok' => $paymentId ? 0 : $loan->pokok,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('sisa_pokok');
        });
    }
};
