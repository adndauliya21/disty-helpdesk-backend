// Pemeriksa alamat foto. Alamat foto hanya boleh berasal dari POST /uploads, yaitu
// .../uploads/<32 karakter hex>.<jpg|png|webp|gif>. Dengan begitu klien tidak bisa menitipkan alamat sembarang
// (misalnya situs lain atau javascript:) ke kolom foto yang nanti ditampilkan di halaman orang lain.
import { GalatHttp } from '../core/http-error.js'

export const POLA = /^(https?:\/\/[A-Za-z0-9.\-]+(:\d+)?)?\/(?:[A-Za-z0-9_.\-]+\/)*uploads\/[a-f0-9]{32}\.(jpg|png|webp|gif)$/
export const PESAN = 'Foto tidak dikenal, silakan unggah ulang'

/** null (hapus foto) atau alamat sah. Selain itu 422. */
export function periksaUrlFoto(nilai) {
  if (nilai === null) return null
  if (typeof nilai !== 'string' || Buffer.byteLength(nilai) > 255 || !POLA.test(nilai)) {
    throw new GalatHttp(422, 'VALIDASI', PESAN, { photo: PESAN })
  }
  return nilai
}
