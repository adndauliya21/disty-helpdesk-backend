// Middleware autentikasi dan otorisasi, padanan App\Http\Middleware\Autentikasi dan PeranDiizinkan.
//   autentikasi : "siapa kamu?" -> 401 bila tidak ada token atau token tidak valid.
//   peran(...)  : "boleh?"      -> 403 bila sudah login tetapi perannya tidak diizinkan. Pasang SETELAH autentikasi.
import { config } from '../config.js'
import { GalatHttp, GalatToken } from '../core/http-error.js'
import { periksaToken } from '../auth/jwt.js'

export function autentikasi(ctx) {
  const [skema = '', token = ''] = String(ctx.req.headers.authorization ?? '').split(' ')
  if (skema.toLowerCase() !== 'bearer' || token === '') {
    throw new GalatHttp(401, 'TIDAK_TERAUTENTIKASI', 'Silakan masuk terlebih dahulu')
  }
  let payload
  try {
    payload = periksaToken(token, config.jwt.rahasia)
  } catch (galat) {
    if (!(galat instanceof GalatToken)) throw galat
    const kedaluwarsa = galat.kode === 'KEDALUWARSA'
    throw new GalatHttp(
      401,
      kedaluwarsa ? 'TOKEN_KEDALUWARSA' : 'TOKEN_TIDAK_VALID',
      kedaluwarsa ? 'Sesi sudah berakhir, silakan masuk lagi' : 'Token tidak valid',
    )
  }
  ctx.pengguna = {
    id: payload.sub ?? null,
    name: payload.name ?? null,
    role: payload.role ?? null,
    instansiCode: payload.instansiCode ?? null,
    technician: payload.technician ?? null,
  }
}

export const peran = (...diizinkan) => (ctx) => {
  if (!diizinkan.includes(ctx.pengguna?.role ?? null)) {
    throw new GalatHttp(403, 'DILARANG', 'Kamu tidak punya akses untuk tindakan ini')
  }
}
