<?php

namespace App\Console\Commands;

use App\Services\IntegrityChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckIntegrity extends Command
{
    protected $signature = 'pinjaman:cek-integritas';

    protected $description = 'Memeriksa konsistensi pinjaman, pembayaran, dan buku kas';

    public function handle(IntegrityChecker $checker): int
    {
        $issues = $checker->check();

        if ($issues === []) {
            $this->info('Tidak ada masalah integritas.');

            return self::SUCCESS;
        }

        $this->table(
            ['Level', 'Kode', 'Objek', 'Pesan'],
            array_map(fn (array $i): array => [$i['level'], $i['kode'], $i['objek'], $i['pesan']], $issues),
        );

        $errors = count(array_filter($issues, fn (array $i): bool => $i['level'] === 'error'));

        if ($errors > 0) {
            Log::error("Cek integritas: {$errors} error ditemukan.", ['issues' => $issues]);
            $this->error("{$errors} error ditemukan.");

            return self::FAILURE;
        }

        $this->warn(count($issues) . ' peringatan (tanpa error).');

        return self::SUCCESS;
    }
}
