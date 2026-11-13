// Pembantu teks kecil.

/** Seperti rawurlencode di PHP: semua karakter selain huruf, angka, - _ . ~ diubah jadi %XX. */
export const rawurlencode = (teks) =>
  encodeURIComponent(teks).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`)

/** "Budi Santoso" -> "budi.santoso" (padanan Str::slug($nama, '.')). */
export const slug = (teks, pemisah = '.') =>
  teks
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, pemisah)
    .replace(new RegExp(`^\\${pemisah}+|\\${pemisah}+$`, 'g'), '')

/** Potong teks di batas karakter (bukan byte), sama dengan mb_substr. */
export const potong = (teks, awal, panjang) => [...teks].slice(awal, awal + panjang).join('')

/** "Bu Rina (SMK 2 Surabaya)" menjadi "Bu Rina". */
export const namaPelapor = (nama) => nama.split(' (')[0].trim()
