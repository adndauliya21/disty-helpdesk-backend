// Kontrak API memakai ISO 8601 UTC dengan milidetik: 2026-09-30T02:10:00.000Z.
// Kolom tanggal dibaca sebagai teks "2026-09-30 02:10:00" (UTC), lalu diubah di sini.
// Padanan App\Support\Waktu di Laravel.
const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
const WIB_MS = 7 * 3600 * 1000   // Asia/Jakarta = UTC+7, tanpa waktu musim panas

const dua = (n) => String(n).padStart(2, '0')

/** Teks kolom database (UTC) menjadi objek Date. */
export const dariDb = (teks) => new Date(`${String(teks).replace(' ', 'T')}Z`)

/** Teks kolom database menjadi ISO dengan milidetik. */
export const iso = (teks) => dariDb(teks).toISOString()

/** Date menjadi teks untuk disimpan ke kolom TIMESTAMP atau DATETIME (UTC, tanpa pecahan detik). */
export const keDb = (tanggal = new Date()) => tanggal.toISOString().slice(0, 19).replace('T', ' ')

/** Sekarang, dalam format kolom database. */
export const sekarangDb = () => keDb(new Date())

/** Tanggal saja (YYYY-MM-DD) dari teks kolom database. */
export const tanggalSaja = (teks) => String(teks).slice(0, 10)

/** "6 Nov 2026" menurut waktu Jakarta, dipakai di layar Instansi. */
export function tanggalWib(teks) {
  const lokal = new Date(dariDb(teks).getTime() + WIB_MS)
  return `${lokal.getUTCDate()} ${BULAN[lokal.getUTCMonth()]} ${lokal.getUTCFullYear()}`
}

/** "10.30" menurut waktu Jakarta. */
export function jamWib(teks) {
  const lokal = new Date(dariDb(teks).getTime() + WIB_MS)
  return `${dua(lokal.getUTCHours())}.${dua(lokal.getUTCMinutes())}`
}

/** Tanggal TTTT-BB-HH benar-benar ada di kalender: 2026-02-30 ditolak. */
export function tanggalValid(teks) {
  const cocok = /^(\d{4})-(\d{2})-(\d{2})$/.exec(teks)
  if (!cocok) return false
  const [tahun, bulan, hari] = [Number(cocok[1]), Number(cocok[2]), Number(cocok[3])]
  const uji = new Date(Date.UTC(2000, 0, 1))
  uji.setUTCFullYear(tahun, bulan - 1, hari)
  return uji.getUTCFullYear() === tahun && uji.getUTCMonth() === bulan - 1 && uji.getUTCDate() === hari
}
