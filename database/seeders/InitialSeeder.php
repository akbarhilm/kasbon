<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Membuat 13 cabang, 13 akun cabang, dan 1 super admin.
 * Password acak ditampilkan SEKALI di konsol; minta pengguna menggantinya saat login pertama.
 * Jalankan: php artisan db:seed --class=InitialSeeder
 */
class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [];

        $sa = (new User)->forceFill([
            'name' => 'Super Admin',
            'email' => env('SA_EMAIL', 'sa@pinjaman.test'),
            'password' => $password = Str::password(14, symbols: false),
            'role' => Role::SuperAdmin,
            'aktif' => true,
        ]);
        $sa->save();
        $rows[] = [$sa->email, $password];

        foreach (range(1, 13) as $i) {
            $kode = sprintf('C%02d', $i);

            $branch = Branch::create(['kode' => $kode, 'nama' => 'Cabang ' . sprintf('%02d', $i)]);

            $user = (new User)->forceFill([
                'name' => 'Admin ' . $branch->nama,
                'email' => strtolower($kode) . '@pinjaman.test', // ganti dengan email asli dari Filament
                'password' => $password = Str::password(14, symbols: false),
                'role' => Role::Cabang,
                'branch_id' => $branch->id,
                'aktif' => true,
            ]);
            $user->save();
            $rows[] = [$user->email, $password];
        }

        $this->command->table(['Email', 'Password awal'], $rows);
    }
}
