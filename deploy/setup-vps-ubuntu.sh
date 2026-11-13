#!/usr/bin/env bash
# [MODUL 7] PERSIAPAN VPS UBUNTU 24.04 (sekali saja): Nginx + PHP-FPM + MySQL + Composer + firewall + situs Nginx + .env awal.
# Letakkan proyek di lokasi akhirnya dulu (mis. /var/www/distyhelpdesk), lalu dari folder itu:
#   sudo DOMAIN=api.contoh.co.id CORS_ORIGIN=https://helpdesk.contoh.co.id ./deploy/setup-vps-ubuntu.sh
# Variabel:
#   DOMAIN        (wajib) nama domain API; DNS A record harus sudah mengarah ke IP server ini
#   CORS_ORIGIN   alamat frontend (bisa diisi nanti di .env)
#   LOCAL_DB      yes (bawaan) = pasang MySQL di server ini | no = pakai database terkelola di cloud (isi .env sendiri)
#   DB_NAME, DB_USER, DB_PASS   bawaan: disty_helpdesk, disty_app, kata sandi acak
#   SSH_PORT      port SSH yang dibuka di firewall (bawaan 22)    FIREWALL=no untuk melewati pengaturan firewall
# Aman diulang, tetapi tidak menimpa .env dan tidak mengubah kata sandi akun MySQL yang sudah ada.
set -euo pipefail
# shellcheck source=deploy/_lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/_lib.sh"

[ "$(id -u)" -eq 0 ] || die "Jalankan dengan sudo."
DOMAIN="${DOMAIN:-}"; [ -n "$DOMAIN" ] || die "Isi DOMAIN, contoh: sudo DOMAIN=api.contoh.co.id $0"
LOCAL_DB="${LOCAL_DB:-yes}"
DB_NAME="${DB_NAME:-disty_helpdesk}"
DB_USER="${DB_USER:-disty_app}"
DB_PASS_DIBUAT="no"
if [ -z "${DB_PASS:-}" ]; then DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"; DB_PASS_DIBUAT="yes"; fi
SSH_PORT="${SSH_PORT:-22}"
OWNER="${SUDO_USER:-root}"
[ -f "$LARAVEL_DIR/artisan" ] || die "artisan tidak ditemukan di $LARAVEL_DIR. Jalankan dari dalam proyek."

info "1/6 Memasang paket (Nginx, PHP-FPM, Composer, Certbot)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip unzip curl git composer openssl ufw certbot python3-certbot-nginx mysql-client
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP di server ini di bawah 8.2. Pakai Ubuntu 24.04 atau pasang PHP 8.2+ dari PPA ondrej/php."
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
systemctl enable --now "php${PHP_VER}-fpm"

if [ "$LOCAL_DB" = "yes" ]; then
  info "2/6 Memasang MySQL dan membuat database + akun khusus aplikasi"
  apt-get install -y mysql-server
  systemctl enable --now mysql
  # Akun aplikasi hanya punya hak pada database ini (bukan root, bukan semua database), dan hanya boleh dari localhost.
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER, SHOW VIEW ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  ok "Database '${DB_NAME}' dan akun '${DB_USER}' siap (MySQL hanya mendengarkan localhost, tidak dibuka ke internet)"
else
  info "2/6 Database terkelola di cloud: lewati MySQL lokal. Isi DB_HOST, DB_USERNAME, DB_PASSWORD (dan MYSQL_ATTR_SSL_CA bila perlu) di .env"
fi

info "3/6 Menyiapkan .env"
if [ -f "$LARAVEL_DIR/.env" ]; then
  echo ".env sudah ada, tidak ditimpa."
else
  cp "$LARAVEL_DIR/.env.production.example" "$LARAVEL_DIR/.env"
  sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" "$LARAVEL_DIR/.env"
  sed -i "s|^JWT_SECRET=.*|JWT_SECRET=$(openssl rand -base64 48 | tr -d '\n')|" "$LARAVEL_DIR/.env"
  if [ "$LOCAL_DB" = "yes" ]; then
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|; s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|; s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" "$LARAVEL_DIR/.env"
  fi
  if [ -n "${CORS_ORIGIN:-}" ]; then sed -i "s|^CORS_ORIGIN=.*|CORS_ORIGIN=${CORS_ORIGIN}|" "$LARAVEL_DIR/.env"; fi
fi

info "4/6 Kepemilikan dan izin folder"
chown -R "$OWNER":www-data "$APP_DIR"
if [ "$OWNER" != "root" ]; then usermod -aG www-data "$OWNER"; fi
chmod 640 "$LARAVEL_DIR/.env"
mkdir -p "$LARAVEL_DIR/storage/framework/"{cache/data,sessions,views} "$LARAVEL_DIR/storage/logs" "$LARAVEL_DIR/bootstrap/cache"
chmod -R ug+rwX "$LARAVEL_DIR/storage" "$LARAVEL_DIR/bootstrap/cache"
find "$LARAVEL_DIR/storage" "$LARAVEL_DIR/bootstrap/cache" -type d -exec chmod g+s {} +
mkdir -p "$LARAVEL_DIR/public/uploads"
chmod -R ug+rwX "$LARAVEL_DIR/public/uploads"   # tempat foto dari POST /uploads
chmod +x "$APP_DIR"/deploy/*.sh

info "5/6 Situs Nginx"
SITUS="/etc/nginx/sites-available/disty-helpdesk"
sed -e "s|__DOMAIN__|${DOMAIN}|g" -e "s|__APP_DIR__|${APP_DIR}|g" -e "s|__PHP_SOCK__|${PHP_SOCK}|g" "$APP_DIR/deploy/nginx.conf.example" > "$SITUS"
ln -sf "$SITUS" /etc/nginx/sites-enabled/disty-helpdesk
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx
systemctl reload nginx

if [ "${FIREWALL:-yes}" = "yes" ]; then
  info "6/6 Firewall (hanya SSH:${SSH_PORT}, HTTP, HTTPS yang terbuka)"
  ufw allow "${SSH_PORT}/tcp"
  ufw allow 'Nginx Full'
  ufw --force enable
else
  info "6/6 Firewall dilewati (FIREWALL=no)"
fi

echo
ok "Server siap."
if [ "$LOCAL_DB" = "yes" ] && [ "$DB_PASS_DIBUAT" = "yes" ]; then
  echo "Kata sandi akun MySQL '${DB_USER}' (sudah ditulis ke .env bila .env baru dibuat): ${DB_PASS}"
  echo "Bila akun itu sudah ada sebelumnya, kata sandinya TIDAK diubah; samakan DB_PASSWORD di .env dengan yang lama."
fi
cat <<LANJUT

Langkah berikutnya (lihat DEPLOY.md):
  1. Cek DNS: ${DOMAIN} harus mengarah ke IP server ini.
  2. Periksa .env (terutama CORS_ORIGIN):   nano ${LARAVEL_DIR}/.env
  3. Keluar-masuk SSH sekali agar keanggotaan grup www-data aktif, lalu sebagai '${OWNER}':
       cd ${APP_DIR} && ./deploy/deploy.sh
  4. Data awal:  cd ${LARAVEL_DIR} && php artisan db:seed --class=ProductionSeeder --force
  5. HTTPS:      sudo certbot --nginx -d ${DOMAIN}
LANJUT
