<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Backup database MySQL ke file .sql.gz (mysqldump, konsisten lewat --single-transaction).
 * Password dikirim lewat variabel lingkungan MYSQL_PWD, tidak muncul di daftar proses.
 */
class BackupDatabase extends Command
{
    protected $signature = 'pinjaman:backup {--keep= : Lama simpan dalam hari (bawaan: config pinjaman.backup.retention_days)}';

    protected $description = 'Backup database ke file .sql.gz dan hapus backup yang melewati masa simpan';

    public function handle(): int
    {
        $connection = config('database.connections.' . config('database.default'));

        if (($connection['driver'] ?? null) !== 'mysql') {
            $this->error('Backup ini hanya mendukung MySQL.');

            return self::FAILURE;
        }

        $dir = rtrim((string) (config('pinjaman.backup.path') ?: storage_path('app/backups')), '/');
        File::ensureDirectoryExists($dir, 0700);

        $file = $dir . '/pinjaman-' . now()->format('Ymd-His') . '.sql.gz';

        $result = Process::timeout(900)
            ->env([
                'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
                'DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
                'DB_PORT' => (string) ($connection['port'] ?? '3306'),
                'DB_USER' => (string) ($connection['username'] ?? ''),
                'DB_NAME' => (string) ($connection['database'] ?? ''),
                'OUT' => $file,
            ])
            ->run(['bash', '-c', 'set -o pipefail; mysqldump --single-transaction --routines --no-tablespaces -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" | gzip > "$OUT"']);

        if ($result->failed() || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);
            $this->error('Backup gagal: ' . trim($result->errorOutput()));

            return self::FAILURE;
        }

        chmod($file, 0600);
        $this->info('Backup tersimpan: ' . $file . ' (' . number_format(filesize($file) / 1024, 0, ',', '.') . ' KB)');

        $days = (int) ($this->option('keep') ?: config('pinjaman.backup.retention_days', 30));
        $threshold = now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach (glob($dir . '/pinjaman-*.sql.gz') ?: [] as $old) {
            if (filemtime($old) < $threshold) {
                unlink($old);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->line("{$removed} backup lama dihapus (lebih dari {$days} hari).");
        }

        return self::SUCCESS;
    }
}
