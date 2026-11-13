// POST /auth/login, GET /auth/me, POST /auth/logout, PUT /me, PUT /me/password,
// POST /auth/activation/request dan /verify. Padanan AuthController di Laravel.
import { randomInt, timingSafeEqual } from 'node:crypto'
import { config } from '../config.js'
import { GalatHttp } from '../core/http-error.js'
import { query, transaksi } from '../db.js'
import { buatToken } from '../auth/jwt.js'
import { buatHash, cocok, HASH_PALSU } from '../auth/password.js'
import { bolehKosong, email, maks, minimal, teks, validasi, wajib } from '../core/validator.js'
import * as pengguna from '../repositories/pengguna.js'
import { dariDb, sekarangDb } from '../support/waktu.js'
import { periksaUrlFoto } from '../support/url-foto.js'
import { akunDariToken } from './dasar.js'

const KODE_AKTIVASI_DETIK = 600   // lama berlaku kode aktivasi yang diumumkan ke frontend

/** Body: { email, password }. Berhasil -> { data: { token, tokenType, expiresIn, user } } */
export async function login(ctx) {
  const isi = await ctx.isi()
  validasi(isi, {
    email: [wajib('Isi email dengan benar'), teks('Isi email dengan benar'), email('Isi email dengan benar')],
    password: [wajib('Isi kata sandi'), teks('Isi kata sandi')],
  })
  const akun = await pengguna.cariEmail(isi.email.trim().toLowerCase())
  const benar = await cocok(isi.password, akun?.password ?? HASH_PALSU)
  // Satu pesan untuk "email salah" dan "kata sandi salah", supaya penyerang tidak tahu mana yang benar.
  // Akun belum aktif (mis. teknisi baru dari POST /technicians) juga ditolak dengan pesan yang sama.
  if (!akun || !benar || akun.status !== 'aktif') {
    throw new GalatHttp(401, 'LOGIN_GAGAL', 'Email atau kata sandi salah')
  }

  // Isi token memakai peran asli database (Klien/Teknisi/Admin) karena middleware peran(...) membandingkannya.
  const klaim = {
    name: akun.nama,
    role: akun.role.charAt(0).toUpperCase() + akun.role.slice(1),
    instansiCode: akun.kode_instansi ?? null,
    technician: akun.role === 'teknisi' ? akun.nama : null,
  }
  const sekarang = sekarangDb()
  await query('UPDATE pengguna SET last_login = ?, updated_at = ? WHERE id = ?', [sekarang, sekarang, akun.id])

  const berlaku = config.jwt.berlakuDetik
  return {
    body: {
      data: {
        token: buatToken({ sub: akun.id, ...klaim }, config.jwt.rahasia, berlaku),
        tokenType: 'Bearer',
        expiresIn: berlaku,
        // Kontrak: tepat empat kunci ini, peran huruf kecil.
        user: { role: akun.role, name: akun.nama, email: akun.email, instansiCode: akun.kode_instansi ?? null },
      },
    },
  }
}

/** POST /auth/logout : 204. Token JWT tidak disimpan di server, jadi tidak ada yang dicabut. Frontend menghapus tokennya sendiri. */
export const logout = () => ({ status: 204 })

/** Siapa saya? Dibaca dari database supaya nama dan email terbaru (setelah PUT /me) ikut tampil. */
export async function me(ctx) {
  return { body: { data: pengguna.profil(await akunDariToken(ctx)) } }
}

/** PUT /me : { name, email, phone, photo }. photo: alamat dari POST /uploads, null = hapus, tidak dikirim = tidak berubah. */
export async function ubahProfil(ctx) {
  const isi = await ctx.isi()
  const akun = await akunDariToken(ctx)
  validasi(isi, {
    name: [
      wajib('Nama dan email yang valid wajib diisi'), teks('Nama dan email yang valid wajib diisi'),
      maks(100, 'Nama maksimal 100 karakter'),
    ],
    email: [
      wajib('Nama dan email yang valid wajib diisi'), teks('Nama dan email yang valid wajib diisi'),
      email('Nama dan email yang valid wajib diisi'), maks(150, 'Email maksimal 150 karakter'),
    ],
    phone: [bolehKosong(), teks('Nomor telepon harus berupa teks'), maks(20, 'Nomor telepon maksimal 20 karakter')],
  })
  const adaFoto = Object.hasOwn(isi, 'photo')
  const foto = adaFoto ? periksaUrlFoto(isi.photo) : null
  const alamat = isi.email.trim().toLowerCase()

  const dipakai = await query('SELECT id FROM pengguna WHERE email = ? AND id <> ? LIMIT 1', [alamat, akun.id])
  if (dipakai.length > 0) throw new GalatHttp(409, 'EMAIL_DIPAKAI', 'Email itu sudah dipakai akun lain')

  const ubah = { nama: isi.name.trim(), email: alamat }
  // Klien yang mengganti email harus tetap berada di instansi terdaftar (domain email menentukan instansinya).
  if (akun.role === 'klien' && alamat !== akun.email) {
    ubah.instansi_id = (await pengguna.instansiWajibAda(alamat)).id
  }
  if (Object.hasOwn(isi, 'phone')) ubah.telepon = String(isi.phone ?? '').trim()   // kolom telepon wajib terisi, kosong disimpan sebagai teks kosong
  if (adaFoto) ubah.foto = foto                                                      // null = hapus foto

  try {
    const kolom = Object.keys(ubah)
    await query(
      `UPDATE pengguna SET ${kolom.map((k) => `${k} = ?`).join(', ')}, updated_at = ? WHERE id = ?`,
      [...Object.values(ubah), sekarangDb(), akun.id],
    )
  } catch (galat) {
    // Dua permintaan bersamaan memakai email yang sama: yang kedua ditolak oleh UNIQUE di database.
    if (galat.errno === 1062) throw new GalatHttp(409, 'EMAIL_DIPAKAI', 'Email itu sudah dipakai akun lain')
    throw galat
  }
  return { body: { data: pengguna.profil(await akunDariToken(ctx)) } }
}

/** PUT /me/password : { currentPassword, newPassword } -> 204.
 *  Kata sandi sengaja TIDAK di-trim (spasi bisa menjadi bagian kata sandi), jadi validasi() bawaan tidak dipakai. */
export async function ubahSandi(ctx) {
  const isi = await ctx.isi()
  const akun = await akunDariToken(ctx)
  const lama = typeof isi.currentPassword === 'string' ? isi.currentPassword : ''
  const baru = typeof isi.newPassword === 'string' ? isi.newPassword : ''

  if (lama === '') throw new GalatHttp(422, 'VALIDASI', 'Isi kata sandi saat ini')
  if (!(await cocok(lama, akun.password))) throw new GalatHttp(403, 'SANDI_SALAH', 'Kata sandi saat ini salah')
  const panjangByte = Buffer.byteLength(baru)
  if (panjangByte < 8 || !/[A-Za-z]/.test(baru) || !/\d/.test(baru)) {
    throw new GalatHttp(422, 'VALIDASI', 'Kata sandi baru minimal 8 karakter dan mengandung huruf serta angka')
  }
  if (panjangByte > 72) throw new GalatHttp(422, 'VALIDASI', 'Kata sandi terlalu panjang (maksimal 72 karakter)')   // batas bcrypt
  if (baru === lama) throw new GalatHttp(422, 'VALIDASI', 'Kata sandi baru harus berbeda dari yang lama')

  await query('UPDATE pengguna SET password = ?, updated_at = ? WHERE id = ?', [await buatHash(baru), sekarangDb(), akun.id])
  return { status: 204 }
}

const HURUF = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
const kodeAcak = (n) => Array.from({ length: n }, () => HURUF[randomInt(HURUF.length)]).join('')

/** POST /auth/activation/request : { email } -> 202 { message, instansiCode, expiresInSeconds }.
 *  Akun nonaktif mendapat kode 8 karakter (kolom pengguna.kode_aktivasi). Pengiriman email belum dipasang,
 *  jadi kode dicatat di konsol server. Balasan sama untuk email terdaftar maupun tidak (keberadaan akun tidak bocor). */
export async function mintaKodeAktivasi(ctx) {
  const isi = await ctx.isi()
  validasi(isi, {
    email: [wajib('Isi email yang valid dulu'), teks('Isi email yang valid dulu'), email('Isi email yang valid dulu')],
  })
  const alamat = isi.email.trim().toLowerCase()
  const [akun] = await query("SELECT id FROM pengguna WHERE email = ? AND status = 'nonaktif'", [alamat])
  if (akun) {
    const kode = kodeAcak(8)
    await query('UPDATE pengguna SET kode_aktivasi = ?, updated_at = ? WHERE id = ?', [kode, sekarangDb(), akun.id])
    console.info(`Kode aktivasi untuk ${alamat}: ${kode}`)
  }
  return {
    status: 202,
    body: {
      data: {
        message: 'Kode aktivasi dikirim',
        instansiCode: await pengguna.kodeDariEmail(alamat),
        expiresInSeconds: KODE_AKTIVASI_DETIK,
      },
    },
  }
}

/** POST /auth/activation/verify : { email, code } -> 200 { activated: true }. Kode salah atau kedaluwarsa -> 422 KODE_SALAH. */
export async function verifikasiKodeAktivasi(ctx) {
  const isi = await ctx.isi()
  validasi(isi, {
    email: [wajib('Isi email yang valid dulu'), teks('Isi email yang valid dulu'), email('Isi email yang valid dulu')],
    code: [
      wajib('Isi kode aktivasi dari email'), teks('Isi kode aktivasi dari email'),
      minimal(4, 'Isi kode aktivasi dari email'), maks(8, 'Kode aktivasi maksimal 8 karakter'),
    ],
  })
  const kode = isi.code.trim().toUpperCase()
  return transaksi(async (s) => {
    const [akun] = await query(
      "SELECT id, kode_aktivasi, updated_at FROM pengguna WHERE email = ? AND status = 'nonaktif' FOR UPDATE",
      [isi.email.trim().toLowerCase()],
      s,
    )
    const batas = Date.now() - KODE_AKTIVASI_DETIK * 1000
    const sama = akun?.kode_aktivasi != null && samaWaktuTetap(String(akun.kode_aktivasi), kode)
    const berlaku = sama && akun.updated_at != null && dariDb(akun.updated_at).getTime() >= batas
    if (!berlaku) throw new GalatHttp(422, 'KODE_SALAH', 'Kode aktivasi salah atau sudah kedaluwarsa')
    await query("UPDATE pengguna SET status = 'aktif', kode_aktivasi = NULL, updated_at = ? WHERE id = ?", [sekarangDb(), akun.id], s)
    return { body: { data: { activated: true } } }
  })
}

/** Bandingkan dua teks dengan waktu tetap (mencegah penebakan kode lewat lama respons). */
function samaWaktuTetap(a, b) {
  const x = Buffer.from(a)
  const y = Buffer.from(b)
  return x.length === y.length && timingSafeEqual(x, y)
}
