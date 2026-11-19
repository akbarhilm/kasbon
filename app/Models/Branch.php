<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class Branch extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // Huruf cabang (A, B, ...) untuk nomor anggota. Jika kosong, diisi huruf pertama yang belum dipakai.
        static::creating(function (Branch $branch): void {
            if (filled($branch->kode_huruf)) {
                $branch->kode_huruf = strtoupper((string) $branch->kode_huruf);

                return;
            }

            $used = static::query()->pluck('kode_huruf')->filter()->all();

            foreach (range('A', 'Z') as $letter) {
                if (! in_array($letter, $used, true)) {
                    $branch->kode_huruf = $letter;

                    return;
                }
            }

            throw new RuntimeException('Kode huruf cabang habis (maksimal 26 cabang).');
        });
    }
}
