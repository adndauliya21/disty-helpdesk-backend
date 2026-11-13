// CRUD teknisi. Teknisi adalah baris tabel pengguna berperan teknisi (kolom nama dan spesialisasi), bukan tabel sendiri.
// Teknisi dikenali lewat namanya di alamat: /technicians/Rafi%20Pratama. Padanan TechnicianController di Laravel.
//   GET    /contacts/technicians terbuka     -> 200 { name, specialty, phone } teknisi aktif (untuk beranda)
//   GET    /technicians         semua peran -> 200 daftar
//   GET    /technicians/:name   semua peran -> 200 | 404
//   POST   /technicians         Admin       -> 201 + Location | 409 bila nama sudah ada | 422 (nama hanya huruf, spesialisasi wajib)
//   PUT    /technicians/:name   Admin       -> 200 | 404 | 422 (nama tidak bisa diubah)
//   DELETE /technicians/:name   Admin       -> 204 | 404 | 409 bila masih dipakai aduan, konsultasi, atau riwayat
import { randomBytes } from 'node:crypto'
import { config } from '../config.js'
import { GalatHttp, tidakDitemukan } from '../core/http-error.js'
import { query } from '../db.js'
import { buatHash } from '../auth/password.js'
import { maks, pola, teks, validasi, wajib } from '../core/validator.js'
import * as pengguna from '../repositories/pengguna.js'
import { rawurlencode, slug } from '../support/teks.js'
import { sekarangDb } from '../support/waktu.js'

async function ambil(nama) {
  const teknisi = await pengguna.teknisiBernama(nama)
  if (!teknisi) throw tidakDitemukan('Teknisi')
  return teknisi
}

export async function index() {
  return { body: { data: (await pengguna.daftarTeknisi()).map(pengguna.bentukTeknisi) } }
}

/** GET /contacts/technicians (terbuka, tanpa login): kontak teknisi untuk beranda. Hanya teknisi aktif yang nomornya terisi. */
export async function kontak() {
  const daftar = (await pengguna.daftarTeknisi())
    .filter((t) => t.status === 'aktif' && String(t.telepon ?? '') !== '')
    .map((t) => ({ name: t.nama, specialty: String(t.spesialisasi ?? ''), phone: String(t.telepon) }))
  return { body: { data: daftar } }
}

export async function show(ctx) {
  return { body: { data: pengguna.bentukTeknisi(await ambil(ctx.params.name)) } }
}

/** Akun teknisi baru dibuat nonaktif dengan kata sandi acak (diaktifkan kemudian lewat aktivasi akun). */
export async function store(ctx) {
  const isi = await ctx.isi()
  validasi(isi, {
    name: [
      wajib('Nama teknisi wajib diisi'), teks('Nama teknisi wajib diisi'), maks(60, 'Nama teknisi maksimal 60 karakter'),
      // Huruf (termasuk berakson), spasi, titik, apostrof, tanda hubung; harus diawali huruf. Sama dengan aturan regex di Laravel.
      pola(/^\p{L}[\p{L} .'-]*$/u, 'Format nama tidak valid: hanya boleh huruf dan spasi (titik, apostrof, dan tanda hubung boleh), tanpa angka atau simbol lain'),
    ],
    specialty: [wajib('Spesialisasi wajib diisi'), teks('Spesialisasi wajib diisi'), maks(50, 'Spesialisasi maksimal 50 karakter')],
  })
  const nama = isi.name.trim()
  if (await pengguna.teknisiBernama(nama)) throw new GalatHttp(409, 'SUDAH_ADA', `Teknisi bernama ${nama} sudah ada`)

  const baru = await buatAkunTeknisi(nama, isi.specialty.trim())
  return {
    status: 201,
    headers: { Location: `/api/v1/technicians/${rawurlencode(nama)}` },
    body: { data: pengguna.bentukTeknisi(baru) },
  }
}

export async function update(ctx) {
  const teknisi = await ambil(ctx.params.name)   // 404 dulu bila tidak ada
  const isi = await ctx.isi()
  validasi(isi, {
    specialty: [wajib('Spesialisasi wajib diisi'), teks('Spesialisasi wajib diisi'), maks(50, 'Spesialisasi maksimal 50 karakter')],
  })
  // Nama dipakai sebagai penanda di API, jadi tidak boleh berubah lewat PUT.
  if (Object.hasOwn(isi, 'name') && isi.name !== ctx.params.name) {
    throw new GalatHttp(422, 'VALIDASI', 'Nama teknisi tidak dapat diubah', { name: 'Nama teknisi tidak dapat diubah' })
  }
  await query('UPDATE pengguna SET spesialisasi = ?, updated_at = ? WHERE id = ?', [isi.specialty.trim(), sekarangDb(), teknisi.id])
  return { body: { data: pengguna.bentukTeknisi(await ambil(ctx.params.name)) } }
}

export async function destroy(ctx) {
  const nama = ctx.params.name
  const teknisi = await ambil(nama)
  const pesan = `Teknisi ${nama} masih tercatat di aduan atau konsultasi, tidak bisa dihapus`
  if (await pengguna.masihDipakai(teknisi.id)) throw new GalatHttp(409, 'MASIH_DIPAKAI', pesan)
  try {
    await query('DELETE FROM pengguna WHERE id = ?', [teknisi.id])
  } catch (galat) {
    // 1451 = pelanggaran foreign key (RESTRICT dari penugasan_teknisi, riwayat_aduan, pesan_konsultasi): masih dipakai.
    if (galat.errno === 1451) throw new GalatHttp(409, 'MASIH_DIPAKAI', pesan)
    throw galat
  }
  return { status: 204 }
}

/** Buat akun pengguna berperan teknisi (nonaktif). Email dari nama: "Budi Santoso" -> budi.santoso@{domain_staf}. */
async function buatAkunTeknisi(nama, spesialisasi) {
  const dasar = slug(nama, '.') || 'teknisi'
  let alamat = `${dasar}@${config.domainStaf}`
  for (let i = 2; await pengguna.cariEmail(alamat); i++) alamat = `${dasar}${i}@${config.domainStaf}`

  const sekarang = sekarangDb()
  const hasil = await query(
    `INSERT INTO pengguna (nama, email, password, telepon, role, spesialisasi, status, created_at, updated_at)
     VALUES (?, ?, ?, '', 'teknisi', ?, 'nonaktif', ?, ?)`,
    [nama, alamat, await buatHash(randomBytes(30).toString('base64url').slice(0, 40)), spesialisasi, sekarang, sekarang],
  )
  return pengguna.cariId(hasil.insertId)
}
