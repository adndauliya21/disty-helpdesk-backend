// Akses tabel pengguna dan instansi. Admin, teknisi, dan klien semuanya baris tabel pengguna (kolom role).
// Padanan model User dan Instansi di Laravel.
import { query } from '../db.js'
import { GalatHttp } from '../core/http-error.js'
import { namaPelapor } from '../support/teks.js'

// kode_instansi hanya untuk klien (admin dan teknisi null), sama dengan aksesor User::kodeInstansi.
const AKUN = `SELECT p.*, CASE WHEN p.role = 'klien' THEN i.kode_instansi END AS kode_instansi
              FROM pengguna p LEFT JOIN instansi i ON i.id = p.instansi_id`

export async function cariId(id, s) {
  const [akun] = await query(`${AKUN} WHERE p.id = ?`, [id], s)
  return akun ?? null
}

export async function cariEmail(email, s) {
  const [akun] = await query(`${AKUN} WHERE p.email = ?`, [email], s)
  return akun ?? null
}

// ---- Instansi dari domain email ----

/** "Rina@SMKN2.sch.id" menjadi "smkn2.sch.id". Email tanpa @ menjadi teks kosong. */
export function domainDari(email) {
  const rapi = String(email).trim().toLowerCase()
  const posisi = rapi.lastIndexOf('@')
  return posisi === -1 ? '' : rapi.slice(posisi + 1)
}

export async function instansiDariEmail(email, s) {
  const domain = domainDari(email)
  if (domain === '') return null
  const [instansi] = await query('SELECT * FROM instansi WHERE domain_email = ? LIMIT 1', [domain], s)
  return instansi ?? null
}

export async function kodeDariEmail(email, s) {
  return (await instansiDariEmail(email, s))?.kode_instansi ?? null
}

/** Instansi dari domain email, atau galat 422 bila domainnya belum terdaftar. */
export async function instansiWajibAda(email, s) {
  const instansi = await instansiDariEmail(email, s)
  if (!instansi) throw new GalatHttp(422, 'INSTANSI_TIDAK_DIKENAL', 'Domain email belum terdaftar sebagai instansi')
  return instansi
}

// ---- Bentuk JSON ----

/** Profil sesuai kontrak GET /auth/me dan PUT /me. */
export const profil = (akun) => ({
  role: akun.role,
  name: akun.nama,
  email: akun.email,
  phone: String(akun.telepon ?? ''),
  photo: akun.foto === '' ? null : (akun.foto ?? null),
  instansiCode: akun.kode_instansi ?? null,
})

export const pelapor = namaPelapor

// ---- Teknisi ----

export async function daftarTeknisi(s) {
  return query("SELECT * FROM pengguna WHERE role = 'teknisi' ORDER BY id", [], s)
}

export async function teknisiBernama(nama, s) {
  const [teknisi] = await query("SELECT * FROM pengguna WHERE role = 'teknisi' AND nama = ? LIMIT 1", [nama], s)
  return teknisi ?? null
}

export async function teknisiBernamaDi(daftarNama, s) {
  if (daftarNama.length === 0) return []
  return query("SELECT * FROM pengguna WHERE role = 'teknisi' AND nama IN (?)", [daftarNama], s)
}

export const bentukTeknisi = (t) => ({ name: t.nama, specialty: String(t.spesialisasi ?? '') })

/** Teknisi masih tercatat di data lain (foreign key RESTRICT di database melindungi penugasan, riwayat, aduan, pesan). */
export async function masihDipakai(id, s) {
  const [hasil] = await query(
    `SELECT
       EXISTS(SELECT 1 FROM penugasan_teknisi WHERE teknisi_id = ?) OR
       EXISTS(SELECT 1 FROM riwayat_aduan WHERE pengguna_id = ?) OR
       EXISTS(SELECT 1 FROM pesan_konsultasi WHERE pengirim_id = ?) OR
       EXISTS(SELECT 1 FROM konsultasi WHERE teknisi_id = ?) AS dipakai`,
    [id, id, id, id],
    s,
  )
  return Boolean(hasil.dipakai)
}
