// Pembantu yang dipakai banyak controller (padanan Controller dasar di Laravel).
import { GalatHttp } from '../core/http-error.js'
import { cariId } from '../repositories/pengguna.js'

/** Akun lengkap dari database (nama dan email terbaru). Token yang akunnya sudah dihapus -> 401. */
export async function akunDariToken(ctx, s) {
  const akun = await cariId(ctx.pengguna.id, s)
  if (!akun) throw new GalatHttp(401, 'TOKEN_TIDAK_VALID', 'Token tidak valid')
  return akun
}

/** Klien dibatasi ke instansinya sendiri, peran lain tidak dibatasi (null = semua). */
export const batasInstansi = (ctx) => (ctx.pengguna.role === 'Klien' ? ctx.pengguna.instansiCode : null)

/** Pastikan isian berupa teks, lalu buang spasi di tepi. Bukan teks -> teks kosong. */
export const teksRapi = (nilai) => (typeof nilai === 'string' ? nilai.trim() : '')
