// Validasi isian ala Laravel (Validator::make) dengan aturan yang dipakai proyek ini.
// Semua pelanggaran dikumpulkan, pesan pertama jadi `message` (tampil sebagai toast di frontend),
// seluruh daftar (satu pesan per field) masuk ke `details`. Gagal -> 422 VALIDASI.
//
// Cara pakai:
//   validasi(isi, {
//     email: [wajib('Isi email'), teks('Isi email'), email('Isi email')],
//     note:  [bolehKosong(), teks('Catatan harus berupa teks'), maks(5000, 'Catatan maksimal 5.000 karakter')],
//   })
// Aturan dalam satu field berhenti di pelanggaran pertama (sama dengan `bail` di Laravel).
import { GalatHttp } from './http-error.js'

export const panjang = (nilai) => [...nilai].length   // hitung karakter, sama dengan mb_strlen

const kosong = (nilai) =>
  nilai === null || nilai === undefined
  || (typeof nilai === 'string' && nilai.trim() === '')
  || (Array.isArray(nilai) && nilai.length === 0)

// Aturan "wajib" bersifat implisit: dievaluasi walau nilainya kosong. Aturan lain dilewati bila nilainya
// teks kosong (perilaku Laravel untuk aturan non-implisit).
export const wajib = (pesan) => ({ implisit: true, cek: (v) => !kosong(v), pesan })
export const bolehKosong = () => ({ nullable: true })
export const teks = (pesan) => ({ cek: (v) => typeof v === 'string', pesan })
export const larik = (pesan) => ({ cek: (v) => Array.isArray(v), pesan })
export const minimal = (n, pesan) => ({
  cek: (v) => (typeof v === 'string' ? panjang(v) >= n : Array.isArray(v) ? v.length >= n : true),
  pesan,
})
export const maks = (n, pesan) => ({
  cek: (v) => (typeof v === 'string' ? panjang(v) <= n : Array.isArray(v) ? v.length <= n : true),
  pesan,
})
// Pola sederhana tapi cukup ketat: tanpa spasi, tepat satu @, ada nama dan domain.
export const email = (pesan) => ({ cek: (v) => typeof v === 'string' && /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*$/.test(v), pesan })
// Cocokkan teks dengan pola (padanan aturan `regex` di Laravel).
export const pola = (regex, pesan) => ({ cek: (v) => typeof v === 'string' && regex.test(v), pesan })
export const bulat = (pesan) => ({
  cek: (v) => Number.isInteger(v) || (typeof v === 'string' && /^[+-]?\d+$/.test(v)),
  pesan,
})
export const antara = (min, max, pesan) => ({ cek: (v) => Number(v) >= min && Number(v) <= max, pesan })

/** Cari nilai di data lewat nama field. Mendukung "handledBy.0" untuk isi larik. */
function ambil(data, nama) {
  let nilai = data
  for (const bagian of nama.split('.')) {
    if (nilai === null || typeof nilai !== 'object' || !Object.hasOwn(nilai, bagian)) return { ada: false }
    nilai = nilai[bagian]
  }
  return { ada: true, nilai }
}

/** Pecah aturan berbintang ("handledBy.*") menjadi aturan per elemen ("handledBy.0", "handledBy.1", ...). */
function kembangkan(data, aturan) {
  const hasil = []
  for (const [nama, daftar] of Object.entries(aturan)) {
    if (!nama.includes('*')) {
      hasil.push([nama, daftar])
      continue
    }
    const induk = nama.split('.*')[0]
    const { ada, nilai } = ambil(data, induk)
    if (ada && Array.isArray(nilai)) nilai.forEach((_, i) => hasil.push([nama.replace('*', i), daftar]))
  }
  return hasil
}

export function validasi(data, aturan) {
  // Teks di tingkat atas di-trim dulu (spasi di tepi tidak dihitung). Elemen larik tidak di-trim.
  const bersih = Object.fromEntries(
    Object.entries(data).map(([k, v]) => [k, typeof v === 'string' ? v.trim() : v]),
  )
  const galat = {}
  for (const [nama, daftar] of kembangkan(bersih, aturan)) {
    const { ada, nilai } = ambil(bersih, nama)
    const bolehNull = daftar.some((a) => a.nullable)
    if (bolehNull && (!ada || nilai === null)) continue
    for (const aturanIni of daftar) {
      if (aturanIni.nullable) continue
      if (!aturanIni.implisit) {
        if (!ada) break                                            // field tidak dikirim: aturan non-implisit dilewati
        if (typeof nilai === 'string' && nilai.trim() === '') break // teks kosong: dilewati
      }
      if (!aturanIni.cek(ada ? nilai : undefined)) {
        galat[nama] = aturanIni.pesan
        break
      }
    }
  }
  const pesanPertama = Object.values(galat)[0]
  if (pesanPertama !== undefined) throw new GalatHttp(422, 'VALIDASI', pesanPertama, galat)
}

/** Baca query string berisi nilai enum. Tidak diisi -> null. Diisi tapi salah -> 422. */
export function enumDariQuery(query, nama, daftar, pesan) {
  const nilai = typeof query[nama] === 'string' ? query[nama].trim() : ''
  if (nilai === '') return null
  if (!daftar.includes(nilai)) throw new GalatHttp(422, 'VALIDASI', pesan)
  return nilai
}
