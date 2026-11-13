// Akses tabel konsultasi, pesan_konsultasi, riwayat_konsultasi, dan kuota_konsultasi.
// Padanan model Konsultasi, ConsultationResource, dan App\Support\KuotaKonsultasi di Laravel.
// Di database jam ditulis "10:00", di API dan frontend "10.00".
import { query } from '../db.js'
import { iso, sekarangDb, tanggalValid } from '../support/waktu.js'
import { namaPelapor, potong } from '../support/teks.js'

export const STATUS_DARI_ADMIN = ['Terjadwal', 'Ditolak', 'Jadi', 'Tidak']
export const STATUS_BEBAS_KUOTA = ['Ditolak', 'Tidak']

export const jamKeDb = (jam) => jam.replaceAll('.', ':')
const jamKeApi = (jam) => String(jam).slice(0, 5).replaceAll(':', '.')

// ---- Konsultasi ----

const KOLOM = `k.*, pg.nama AS pengaju_nama,
  CASE WHEN pg.role = 'klien' THEN i.kode_instansi END AS kode_instansi, tk.nama AS teknisi_nama`
const DARI = `FROM konsultasi k
  LEFT JOIN pengguna pg ON pg.id = k.pengaju_id
  LEFT JOIN instansi i ON i.id = pg.instansi_id
  LEFT JOIN pengguna tk ON tk.id = k.teknisi_id`

async function lengkapi(baris, s) {
  if (baris.length === 0) return baris
  const ids = baris.map((b) => b.id)
  const [pesan, riwayat] = await Promise.all([
    query(
      `SELECT ps.konsultasi_id, ps.pesan, ps.created_at, u.role
         FROM pesan_konsultasi ps LEFT JOIN pengguna u ON u.id = ps.pengirim_id
        WHERE ps.konsultasi_id IN (?) ORDER BY ps.created_at, ps.id`,
      [ids],
      s,
    ),
    query(
      'SELECT konsultasi_id, aktivitas, created_at FROM riwayat_konsultasi WHERE konsultasi_id IN (?) ORDER BY created_at, id',
      [ids],
      s,
    ),
  ])
  for (const b of baris) {
    b.pesan = pesan.filter((p) => p.konsultasi_id === b.id)
    b.riwayat = riwayat.filter((r) => r.konsultasi_id === b.id)
  }
  return baris
}

/** Semua konsultasi, terbaru di depan. kodeInstansi diisi untuk Klien. */
export async function daftar(kodeInstansi, s) {
  const where = kodeInstansi === null ? '' : "WHERE pg.role = 'klien' AND i.kode_instansi = ?"
  const baris = await query(`SELECT ${KOLOM} ${DARI} ${where} ORDER BY k.id DESC`, kodeInstansi === null ? [] : [kodeInstansi], s)
  return lengkapi(baris, s)
}

export async function cariId(id, s) {
  const baris = await query(`SELECT ${KOLOM} ${DARI} WHERE k.id = ?`, [id], s)
  return (await lengkapi(baris, s))[0] ?? null
}

export async function kunci(id, s) {
  await query('SELECT id FROM konsultasi WHERE id = ? FOR UPDATE', [id], s)
}

export async function buat({ pengajuId, topik, catatan, tanggal, jam }, s) {
  const sekarang = sekarangDb()
  const hasil = await query(
    `INSERT INTO konsultasi (pengaju_id, topik, catatan, tanggal, jam, status, jumlah_ubah_jadwal, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 'Menunggu', 0, ?, ?)`,
    [pengajuId, topik, catatan, tanggal, jamKeDb(jam), sekarang, sekarang],
    s,
  )
  return hasil.insertId
}

export async function perbarui(id, ubah, s) {
  const kolom = Object.keys(ubah)
  await query(
    `UPDATE konsultasi SET ${kolom.map((k) => `${k} = ?`).join(', ')}, updated_at = ? WHERE id = ?`,
    [...Object.values(ubah), sekarangDb(), id],
    s,
  )
}

export async function tambahPesan(konsultasiId, pengirimId, pesan, s) {
  const sekarang = sekarangDb()
  await query(
    'INSERT INTO pesan_konsultasi (konsultasi_id, pengirim_id, pesan, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    [konsultasiId, pengirimId, pesan, sekarang, sekarang],
    s,
  )
}

/** Tambah baris riwayat (kolom aktivitas maksimal 255 karakter). */
export async function catat(konsultasiId, aktivitas, s) {
  const sekarang = sekarangDb()
  await query(
    'INSERT INTO riwayat_konsultasi (konsultasi_id, aktivitas, created_at, updated_at) VALUES (?, ?, ?, ?)',
    [konsultasiId, potong(aktivitas, 0, 255), sekarang, sekarang],
    s,
  )
}

/** Jumlah konsultasi yang masih memakai kuota pada tanggal dan jam itu (yang bukan Ditolak atau Tidak). Baris dikunci. */
export async function jumlahPemakaiKuota(tanggal, jam, s) {
  const baris = await query(
    'SELECT id FROM konsultasi WHERE tanggal = ? AND jam = ? AND status NOT IN (?) FOR UPDATE',
    [tanggal, jamKeDb(jam), STATUS_BEBAS_KUOTA],
    s,
  )
  return baris.length
}

/** Bentuk JSON konsultasi sesuai kontrak (urutan kunci sama dengan ConsultationResource). */
export function keKontrak(k) {
  return {
    id: k.id,
    instansiCode: k.kode_instansi ?? null,
    reporter: k.pengaju_nama == null ? null : namaPelapor(k.pengaju_nama),
    topic: k.topik,
    note: String(k.catatan ?? ''),
    date: String(k.tanggal).slice(0, 10),
    time: jamKeApi(k.jam),
    status: k.status,
    technician: k.teknisi_nama ?? '',
    createdAt: iso(k.created_at),
    rescheduled: k.jumlah_ubah_jadwal,
    messages: k.pesan.map((p) => ({ from: p.role === 'klien' ? 'k' : 'a', text: p.pesan, at: iso(p.created_at) })),
    logs: k.riwayat.map((r) => ({ at: iso(r.created_at), message: r.aktivitas })),
  }
}

// ---- Kuota jadwal ----
//   tanggal NULL   = kuota bawaan jam itu (berlaku semua hari kerja)  -> defaults
//   tanggal terisi = kuota khusus untuk tanggal dan jam itu           -> overrides
// Kuota sebuah jam pada sebuah tanggal = kuota khusus bila ada, kalau tidak kuota bawaan jam itu, kalau tidak KUOTA_AWAL.

/** Jam konsultasi tetap, sama dengan SLOTS di frontend. */
export const JAM = ['09.00', '10.00', '11.00', '13.00', '14.00', '15.00']
export const KUOTA_AWAL = 2
export const KUOTA_MAKS = 20

async function bacaKuota(s) {
  const defaults = Object.fromEntries(JAM.map((j) => [j, KUOTA_AWAL]))
  const overrides = {}
  for (const baris of await query('SELECT tanggal, jam, kuota FROM kuota_konsultasi ORDER BY id', [], s)) {
    const jam = jamKeApi(baris.jam)
    if (!JAM.includes(jam)) continue
    if (baris.tanggal === null) defaults[jam] = Number(baris.kuota)
    else overrides[`${String(baris.tanggal).slice(0, 10)}|${jam}`] = Number(baris.kuota)
  }
  return { defaults, overrides }
}

/** Kuota jam tertentu pada tanggal tertentu, atau null bila jam itu bukan salah satu JAM. */
export async function kuotaJam(jam, tanggal, s) {
  if (!JAM.includes(jam)) return null
  const { defaults, overrides } = await bacaKuota(s)
  return Number(overrides[`${tanggal}|${jam}`] ?? defaults[jam])
}

/** Seluruh kuota dalam bentuk JSON kontrak. `overrides` kosong tetap berupa objek {}. */
export async function semuaKuota(s) {
  const { defaults, overrides } = await bacaKuota(s)
  const urut = Object.fromEntries(Object.entries(overrides).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)))
  return { defaults, overrides: urut }
}

/** Ganti seluruh kuota (bukan digabung). Data sudah divalidasi pemanggil. */
export async function simpanKuota(defaults, overrides, adminId, s) {
  const sekarang = sekarangDb()
  await query('DELETE FROM kuota_konsultasi', [], s)
  const baris = JAM.map((jam) => [adminId, null, jamKeDb(jam), Number(defaults[jam]), sekarang, sekarang])
  for (const [kunci, kuota] of Object.entries(overrides)) {
    const [tanggal, jam] = kunci.split('|')
    baris.push([adminId, tanggal, jamKeDb(jam), Number(kuota), sekarang, sekarang])
  }
  await query('INSERT INTO kuota_konsultasi (diatur_oleh, tanggal, jam, kuota, created_at, updated_at) VALUES ?', [baris], s)
}

/** Bilangan bulat 0 sampai 20. */
export const kuotaValid = (nilai) => Number.isInteger(nilai) && nilai >= 0 && nilai <= KUOTA_MAKS

/** Kunci kuota khusus harus "TTTT-BB-HH|JJ.MM" dengan tanggal yang ada di kalender dan jam salah satu JAM. */
export function kunciValid(kunci) {
  const bagian = String(kunci).split('|')
  return bagian.length === 2 && JAM.includes(bagian[1]) && tanggalValid(bagian[0])
}
