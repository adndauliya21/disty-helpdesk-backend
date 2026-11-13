#!/usr/bin/env bash
# [MODUL 7] DEPLOY / UPDATE di server. Aman dijalankan berulang (deploy pertama maupun update berikutnya).
# Pemakaian (dari folder induk distyhelpdesk/, sebagai pemilik folder, BUKAN root):   ./deploy/deploy.sh
# Urutan: mode pemeliharaan -> ambil kode -> composer (tanpa paket dev) -> backup database -> migrate -> cache config & rute
#         -> izin folder -> aplikasi hidup lagi -> periksa produksi -> cek /ping
# Variabel opsional:  SKIP_BACKUP=1 (lewati backup sebelum migrasi)   PHP_BIN=php8.3   COMPOSER_BIN=composer   WEB_GROUP=www-data
set -euo pipefail
umask 002                              # berkas baru dapat ditulis grup web server (www-data)
# shellcheck source=deploy/_lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/_lib.sh"
cd "$LARAVEL_DIR"                       # semua perintah artisan dan composer dijalankan dari dalam laravel/

PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"
WEB_GROUP="${WEB_GROUP:-www-data}"
LANGKAH="persiapan"

[ -f .env ] || die "laravel/.env belum ada. Salin laravel/.env.production.example menjadi laravel/.env lalu isi nilainya."
[ "$(id -u)" -ne 0 ] || warn "Dijalankan sebagai root. Sebaiknya pakai pengguna biasa pemilik folder."
"$PHP" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "Butuh PHP 8.2 atau lebih baru."

gagal() {
  printf '\033[1;31mGAGAL\033[0m pada langkah: %s\n' "$LANGKAH" >&2
  echo "Aplikasi dibiarkan dalam mode pemeliharaan (pengguna melihat 503, data tidak setengah jalan)." >&2
  echo "Perbaiki masalahnya lalu jalankan ulang deploy.sh. Untuk membuka aplikasi tanpa deploy: $PHP artisan up" >&2
}
trap gagal ERR

LANGKAH="mode pemeliharaan";   info "1/8 Mode pemeliharaan"
"$PHP" artisan down --retry=60 2>/dev/null || true       # deploy pertama: vendor/ belum ada, tidak apa-apa

LANGKAH="ambil kode";          info "2/8 Ambil kode terbaru"
if [ -d "$APP_DIR/.git" ]; then git -C "$APP_DIR" pull --ff-only; else echo "Bukan repositori git: pakai kode yang sudah diunggah."; fi

LANGKAH="composer install";    info "3/8 Pasang dependensi (tanpa paket pengembangan)"
"$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

LANGKAH="APP_KEY";             info "4/8 Kunci aplikasi"
if [ -z "$(env_get APP_KEY)" ]; then "$PHP" artisan key:generate --force; else echo "APP_KEY sudah terisi, tidak diubah."; fi

LANGKAH="backup sebelum migrasi"; info "5/8 Backup database sebelum migrasi"
if [ "${SKIP_BACKUP:-0}" = "1" ]; then warn "Backup dilewati (SKIP_BACKUP=1)"; else "$APP_DIR/deploy/backup-db.sh"; fi

LANGKAH="migrate";             info "6/8 Migrasi database (hanya yang belum dijalankan)"
"$PHP" artisan config:clear >/dev/null
"$PHP" artisan migrate --force

LANGKAH="cache";               info "7/8 Cache konfigurasi dan rute"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
chmod 640 .env 2>/dev/null || true
chgrp -R "$WEB_GROUP" storage bootstrap/cache 2>/dev/null || warn "chgrp ke grup $WEB_GROUP gagal (pengguna ini bukan anggota grupnya?)"
chmod -R ug+rwX storage bootstrap/cache
# Folder unggahan foto (POST /uploads): web server harus bisa menulis, dan isinya tidak boleh hilang saat deploy.
mkdir -p public/uploads
chgrp -R "$WEB_GROUP" public/uploads 2>/dev/null || true
chmod -R ug+rwX public/uploads

LANGKAH="aplikasi hidup";      info "8/8 Aplikasi hidup lagi"
"$PHP" artisan up
trap - ERR

STATUS=0
echo
info "Pemeriksaan produksi"
"$PHP" artisan disty:periksa-produksi || STATUS=1

echo
URL="$(env_get APP_URL)/api/v1/ping"
info "Cek kesehatan: $URL"
if curl -fsS --max-time 10 "$URL"; then echo; ok "Server menjawab."; else echo; warn "Server belum menjawab di $URL (cek DNS, Nginx, dan sertifikat)."; STATUS=1; fi

[ "$STATUS" -eq 0 ] && ok "Deploy selesai." || warn "Deploy selesai, tetapi ada butir yang perlu diperbaiki (lihat di atas)."
exit "$STATUS"
