<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('kode_huruf', 1)->nullable()->unique()->after('kode');
            $table->unsignedInteger('member_seq')->default(0)->after('nama');
        });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->after('branch_id');
            $table->string('nomor_anggota', 10)->nullable()->after('nik');
            $table->unique(['branch_id', 'nik']);
            $table->unique(['branch_id', 'nomor_anggota']);
        });

        Schema::table('loans', function (Blueprint $table) {
            // Terisi NIK peminjam selama pinjaman aktif, NULL jika lunas atau dihapus.
            // Unique index ini menjamin satu NIK hanya punya satu pinjaman aktif di SELURUH cabang.
            $table->string('active_nik', 16)->nullable()->unique()->after('active_borrower_id');
            $table->json('foto')->nullable()->after('alasan_hapus');
        });

        // Isi data yang sudah ada: huruf cabang (A, B, ...) menurut urutan id, lalu nomor anggota per cabang.
        $letter = 0;
        foreach (DB::table('branches')->orderBy('id')->get() as $branch) {
            if ($letter >= 26) {
                break;
            }

            DB::table('branches')->where('id', $branch->id)->update(['kode_huruf' => chr(65 + $letter++)]);
        }

        foreach (DB::table('branches')->whereNotNull('kode_huruf')->orderBy('id')->get() as $branch) {
            $n = 0;

            foreach (DB::table('borrowers')->where('branch_id', $branch->id)->orderBy('id')->pluck('id') as $id) {
                $n++;
                DB::table('borrowers')->where('id', $id)->update([
                    'nomor_anggota' => sprintf('%s%04d', $branch->kode_huruf, $n),
                ]);
            }

            DB::table('branches')->where('id', $branch->id)->update(['member_seq' => $n]);
        }
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique(['active_nik']);
            $table->dropColumn(['active_nik', 'foto']);
        });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'nik']);
            $table->dropUnique(['branch_id', 'nomor_anggota']);
            $table->dropColumn(['nik', 'nomor_anggota']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['kode_huruf']);
            $table->dropColumn(['kode_huruf', 'member_seq']);
        });
    }
};
