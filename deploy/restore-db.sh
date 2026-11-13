#!/usr/bin/env bash
# [MODUL 7] PULIHKAN DATABASE PRODUCTION dari berkas backup (.sql atau .sql.gz).
# Pemakaian:  ./deploy/restore-db.sh laravel/storage/backups/disty_helpdesk_20261028_020000.sql.gz
# AWAS: tabel di database saat ini DIGANTI dengan isi backup. Sebelum memulihkan, skrip ini membuat backup pengaman
# dari kondisi sekarang, dan meminta kamu mengetik nama database sebagai konfirmasi.
set -euo pipefail
# shellcheck source=deploy/_lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/_lib.sh"
muat_koneksi_db

BERKAS="${1:-}"
[ -n "$BERKAS" ] || die "Pemakaian: $0 <berkas-backup.sql.gz>"
[ -f "$BERKAS" ] || die "Berkas tidak ditemukan: $BERKAS"
command -v mysql >/dev/null || die "klien mysql belum terpasang (sudo apt install mysql-client)"

warn "Database '$DB_NAME' di $DB_HOST akan ditimpa oleh isi $BERKAS"
read -r -p "Ketik nama database ($DB_NAME) untuk melanjutkan: " JAWAB
[ "$JAWAB" = "$DB_NAME" ] || die "Dibatalkan."

info "Membuat backup pengaman kondisi sekarang"
"$(dirname "${BASH_SOURCE[0]}")/backup-db.sh"

info "Memulihkan..."
if [[ "$BERKAS" == *.gz ]]; then BACA=(gzip -dc "$BERKAS"); else BACA=(cat "$BERKAS"); fi
"${BACA[@]}" | MYSQL_PWD="$DB_PASS" mysql --default-character-set=utf8mb4 -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "${DB_SSL_ARGS[@]}" "$DB_NAME"
ok "Pemulihan selesai. Jalankan:  php artisan disty:periksa-produksi"
