#!/usr/bin/env bash
# Latihan restore: memulihkan backup terbaru ke database sementara dan membandingkan jumlah data dengan database live.
# Jalankan bulanan. Membutuhkan akses mysql lewat ~/.my.cnf (user dengan hak CREATE/DROP database sementara).
#   bash deploy/restore-test.sh
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/pinjaman}"
LIVE_DB="${LIVE_DB:-pinjaman}"
SCRATCH_DB="${SCRATCH_DB:-pinjaman_restore_test}"
TABLES=(branches users borrowers loans payments ledger_entries audit_logs)

LATEST="$(ls -1t "$BACKUP_DIR"/pinjaman-*.sql.gz 2>/dev/null | head -n1 || true)"
if [ -z "$LATEST" ]; then
    echo "Tidak ada backup di $BACKUP_DIR" >&2
    exit 1
fi
echo "Backup terbaru : $LATEST"
echo "Ukuran         : $(du -h "$LATEST" | cut -f1)"

mysql -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`; CREATE DATABASE \`$SCRATCH_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c "$LATEST" | mysql "$SCRATCH_DB"

status=0
printf '%-16s %10s %10s\n' "Tabel" "Backup" "Live"
for t in "${TABLES[@]}"; do
    restored="$(mysql -N -e "SELECT COUNT(*) FROM \`$t\`" "$SCRATCH_DB")"
    live="$(mysql -N -e "SELECT COUNT(*) FROM \`$t\`" "$LIVE_DB")"
    mark=""
    # Live boleh lebih banyak (transaksi setelah backup), tidak boleh lebih sedikit.
    if [ "$restored" -gt "$live" ]; then mark="  <-- backup lebih banyak dari live, periksa"; status=1; fi
    printf '%-16s %10s %10s%s\n' "$t" "$restored" "$live" "$mark"
done

SALDO="$(mysql -N -e "SELECT COALESCE(SUM(CASE WHEN arah='masuk' THEN jumlah ELSE -jumlah END),0) FROM ledger_entries" "$SCRATCH_DB")"
echo "Total saldo semua cabang pada backup: $SALDO"

mysql -e "DROP DATABASE \`$SCRATCH_DB\`"

if [ "$status" -eq 0 ]; then
    echo "Restore berhasil."
else
    echo "Restore selesai dengan catatan, periksa tabel di atas." >&2
fi
exit "$status"
