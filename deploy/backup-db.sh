#!/usr/bin/env bash
# [MODUL 7] BACKUP DATABASE PRODUCTION: dump MySQL -> dikompres -> disimpan di luar folder public -> yang lama dihapus.
# Pemakaian:  ./deploy/backup-db.sh
# Pengaturan lewat variabel lingkungan (opsional):
#   BACKUP_DIR     folder tujuan                         (bawaan: laravel/storage/backups)
#   KEEP_DAYS      berapa hari backup disimpan           (bawaan: 14)
#   BACKUP_REMOTE  tujuan rclone untuk salinan di cloud, mis. "gdrive:disty-backup" (opsional, butuh rclone terpasang)
# Kata sandi database dibaca dari .env dan diberikan lewat MYSQL_PWD, jadi tidak tampil di daftar proses (ps).
set -euo pipefail
# shellcheck source=deploy/_lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/_lib.sh"
muat_koneksi_db

BACKUP_DIR="${BACKUP_DIR:-$LARAVEL_DIR/storage/backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

BERKAS="$BACKUP_DIR/${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
trap 'rm -f "$BERKAS.tmp"' EXIT

# --single-transaction: dump konsisten tanpa mengunci tabel, aplikasi tetap bisa melayani permintaan.
OPSI=(--single-transaction --default-character-set=utf8mb4 -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "${DB_SSL_ARGS[@]}")
if mysqldump --help 2>/dev/null | grep -q -- '--no-tablespaces'; then OPSI+=(--no-tablespaces); fi

info "Backup database '$DB_NAME' ke $BERKAS"
MYSQL_PWD="$DB_PASS" mysqldump "${OPSI[@]}" "$DB_NAME" | gzip > "$BERKAS.tmp"
gzip -t "$BERKAS.tmp"                                  # pastikan arsip utuh
[ "$(gzip -dc "$BERKAS.tmp" | head -c 200 | wc -c)" -gt 0 ] || die "Hasil backup kosong"
mv "$BERKAS.tmp" "$BERKAS"
chmod 600 "$BERKAS"
ok "Selesai ($(du -h "$BERKAS" | cut -f1))"

if [ -n "${BACKUP_REMOTE:-}" ]; then
  command -v rclone >/dev/null || die "BACKUP_REMOTE diisi tetapi rclone belum terpasang"
  info "Menyalin ke cloud: $BACKUP_REMOTE"
  rclone copy "$BERKAS" "$BACKUP_REMOTE"
  ok "Salinan cloud selesai"
fi

find "$BACKUP_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz" -type f -mtime +"$KEEP_DAYS" -delete
echo "$BERKAS"
