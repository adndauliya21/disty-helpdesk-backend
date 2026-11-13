#!/usr/bin/env bash
# [MODUL 7] Uji asap (smoke test) setelah deploy: memastikan API di server benar-benar hidup dan aman.
# Pemakaian: ./deploy/cek-deploy.sh https://api.contoh.id        (tanpa argumen: http://localhost:8000)
set -u
BASE="${1:-http://localhost:8000}"; BASE="${BASE%/}"
LULUS=0; GAGAL=0

cek() { # cek "nama" "kode-diharapkan" "kode-sebenarnya"
  if [ "$2" = "$3" ]; then printf '\033[1;32m  lulus\033[0m  %s (HTTP %s)\n' "$1" "$3"; LULUS=$((LULUS+1))
  else printf '\033[1;31m  GAGAL\033[0m  %s (diharapkan %s, didapat %s)\n' "$1" "$2" "$3"; GAGAL=$((GAGAL+1)); fi
}
kode() { curl -s -o /dev/null -m 15 -w '%{http_code}' "$@" 2>/dev/null || echo 000; }

echo "Memeriksa $BASE"
cek "GET /api/v1/ping hidup"                200 "$(kode "$BASE/api/v1/ping")"
cek "Daftar aduan tanpa token ditolak"      401 "$(kode "$BASE/api/v1/tickets")"
cek "Login dengan data kosong ditolak"      422 "$(kode -X POST -H 'Content-Type: application/json' -d '{}' "$BASE/api/v1/auth/login")"
cek "Metode salah dijawab 405"              405 "$(kode -X DELETE "$BASE/api/v1/tickets")"
cek "Alamat asing dijawab 404"              404 "$(kode "$BASE/api/v1/tidak-ada")"

CORS="$(curl -s -D - -o /dev/null -m 15 -H 'Origin: https://contoh.id' "$BASE/api/v1/ping" | tr -d '\r' | grep -i '^access-control-allow-origin' || true)"
[ -n "$CORS" ] && cek "Header CORS ada" ok ok || cek "Header CORS ada" ok kosong

case "$BASE" in
  https://*) cek "HTTPS aktif" 200 "$(kode "$BASE/api/v1/ping")"
             HEADER="$(curl -s -D - -o /dev/null -m 15 "http://${BASE#https://}/api/v1/ping" | head -1 | tr -d '\r')"
             case "$HEADER" in *301*|*302*|*308*) cek "HTTP dialihkan ke HTTPS" ok ok ;; *) cek "HTTP dialihkan ke HTTPS" ok "$HEADER" ;; esac ;;
  *) printf '  (lewati pemeriksaan HTTPS: alamat bukan https)\n' ;;
esac

printf '\n%d lulus, %d gagal\n' "$LULUS" "$GAGAL"
[ "$GAGAL" -eq 0 ]
