#!/usr/bin/env bash
# [MODUL 7] Fungsi bersama untuk skrip di folder deploy/. Tidak dijalankan langsung (di-source oleh skrip lain).

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # folder induk distyhelpdesk/
LARAVEL_DIR="$APP_DIR/laravel"                             # tempat artisan, .env, storage, public

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m OK\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mPERINGATAN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mGAGAL:\033[0m %s\n' "$*" >&2; exit 1; }

# Baca satu nilai dari .env tanpa meng-source file-nya (kata sandi boleh mengandung karakter khusus).
# Pemakaian: env_get KUNCI [nilai-bawaan]
env_get() {
  local line v
  line="$(grep -E "^[[:space:]]*$1=" "$LARAVEL_DIR/.env" 2>/dev/null | tail -n1 || true)"
  v="${line#*=}"
  v="${v%$'\r'}"
  if [[ "$v" =~ ^\"(.*)\"$ ]]; then
    v="${BASH_REMATCH[1]}"
  elif [[ "$v" =~ ^\'(.*)\'$ ]]; then
    v="${BASH_REMATCH[1]}"
  else
    v="${v%% #*}"                       # buang komentar di ujung baris (nilai tanpa kutip)
    v="${v%"${v##*[![:space:]]}"}"      # buang spasi di ujung
  fi
  if [ -z "$line" ] || [ -z "$v" ]; then v="${2:-}"; fi
  printf '%s' "$v"
}

# Siapkan variabel koneksi database dari .env (dipakai backup-db.sh dan restore-db.sh).
# shellcheck disable=SC2034  # variabel dipakai skrip yang men-source file ini
muat_koneksi_db() {
  [ -f "$LARAVEL_DIR/.env" ] || die "File .env tidak ditemukan di $LARAVEL_DIR"
  [ "$(env_get DB_CONNECTION mysql)" = "mysql" ] || die "Skrip ini hanya untuk DB_CONNECTION=mysql"
  DB_HOST="$(env_get DB_HOST 127.0.0.1)"
  DB_PORT="$(env_get DB_PORT 3306)"
  DB_NAME="$(env_get DB_DATABASE disty_helpdesk)"
  DB_USER="$(env_get DB_USERNAME root)"
  DB_PASS="$(env_get DB_PASSWORD)"
  DB_SSL_CA="$(env_get MYSQL_ATTR_SSL_CA)"
  DB_SSL_ARGS=()
  if [ -n "$DB_SSL_CA" ]; then DB_SSL_ARGS=(--ssl-ca="$DB_SSL_CA"); fi
  command -v mysqldump >/dev/null || die "mysqldump belum terpasang (sudo apt install mysql-client)"
}
