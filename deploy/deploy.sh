#!/usr/bin/env bash
# Deploy rilis baru. Jalankan di server dari folder aplikasi:  bash deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

on_error() {
    echo
    echo "DEPLOY GAGAL. Aplikasi sengaja dibiarkan dalam mode maintenance."
    echo "Lihat DEPLOY.md bagian Rollback. Backup sebelum deploy ada di: ${BACKUP_PATH:-storage/app/backups}"
}
trap on_error ERR

echo "== 1. Backup database sebelum deploy"
php artisan pinjaman:backup

echo "== 2. Mode maintenance"
php artisan down --retry=60 || true

echo "== 3. Ambil kode"
git pull --ff-only

echo "== 4. Dependensi"
composer install --no-dev --optimize-autoloader --no-interaction

echo "== 5. Migrasi database"
php artisan migrate --force

echo "== 6. Cache"
php artisan optimize:clear
php artisan filament:optimize || true
php artisan optimize

echo "== 7. Cek integritas data"
php artisan pinjaman:cek-integritas || echo "PERINGATAN: ada masalah integritas, periksa sebelum membuka kembali untuk user."

echo "== 8. Aplikasi kembali online"
php artisan up
sudo systemctl reload php8.3-fpm

echo "Deploy selesai."
