// JWT (JSON Web Token) HS256, ditulis sendiri dengan modul crypto bawaan Node supaya terlihat cara kerjanya.
// Token = tiga bagian dipisah titik:   header.payload.tanda-tangan   (masing-masing base64url)
//   header        : {"alg":"HS256","typ":"JWT"}
//   payload       : data pengguna + waktu terbit (iat) + waktu kedaluwarsa (exp)
//   tanda tangan  : HMAC-SHA256(header.payload, rahasia)
// Payload hanya di-encode, BUKAN dienkripsi. Jangan taruh kata sandi atau data rahasia di dalamnya.
// Format sama persis dengan App\Support\Jwt di Laravel, jadi token kedua backend saling diterima
// selama JWT_SECRET sama.
import { createHmac, timingSafeEqual } from 'node:crypto'
import { GalatToken } from '../core/http-error.js'

const b64 = (buffer) => Buffer.from(buffer).toString('base64url')
const tandaTangan = (isi, rahasia) => createHmac('sha256', rahasia).update(isi).digest()

function dekode(bagian) {
  // Hanya karakter base64url yang sah. Buffer.from diam-diam mengabaikan karakter lain.
  if (!/^[A-Za-z0-9_-]*$/.test(bagian)) return null
  return Buffer.from(bagian, 'base64url')
}

function dekodeJson(bagian) {
  const mentah = dekode(bagian)
  if (mentah === null) return null
  try {
    const nilai = JSON.parse(mentah.toString('utf8'))
    return nilai !== null && typeof nilai === 'object' && !Array.isArray(nilai) ? nilai : null
  } catch {
    return null
  }
}

export function buatToken(payload, rahasia, berlakuDetik) {
  const sekarang = Math.floor(Date.now() / 1000)
  const header = b64(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))
  const isi = b64(JSON.stringify({ ...payload, iat: sekarang, exp: sekarang + berlakuDetik }))
  return `${header}.${isi}.${b64(tandaTangan(`${header}.${isi}`, rahasia))}`
}

/** Kembalikan payload bila token sah. Selain itu lempar GalatToken (TIDAK_VALID atau KEDALUWARSA). */
export function periksaToken(token, rahasia) {
  const bagian = token.split('.')
  if (bagian.length !== 3) throw new GalatToken('TIDAK_VALID', 'Token rusak')
  const [header, isi, ttd] = bagian
  const dataHeader = dekodeJson(header)
  const payload = dekodeJson(isi)
  if (!dataHeader || !payload) throw new GalatToken('TIDAK_VALID', 'Token rusak')

  // Tolak algoritma lain (termasuk "none"): hanya HS256 yang kita terbitkan.
  if (dataHeader.alg !== 'HS256') throw new GalatToken('TIDAK_VALID', 'Algoritma token tidak didukung')

  const seharusnya = tandaTangan(`${header}.${isi}`, rahasia)
  const diterima = dekode(ttd)
  // timingSafeEqual: pembanding waktu-tetap, dan harus sama panjang.
  if (diterima === null || diterima.length !== seharusnya.length || !timingSafeEqual(seharusnya, diterima)) {
    throw new GalatToken('TIDAK_VALID', 'Tanda tangan token tidak cocok')
  }
  if (!Number.isInteger(payload.exp) || payload.exp <= Math.floor(Date.now() / 1000)) {
    throw new GalatToken('KEDALUWARSA', 'Token sudah kedaluwarsa')
  }
  return payload
}
