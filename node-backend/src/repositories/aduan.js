// Akses tabel aduan dan tabel-tabel yang menempel padanya: modul, penugasan_teknisi, riwayat_aduan, solusi_aduan.
// Padanan model Aduan + TicketResource di Laravel. Satu aduan tersebar di beberapa tabel, jadi pemuatannya
// dilakukan per tabel (bukan satu JOIN raksasa) lalu dirakit, supaya jumlah query tetap tetap berapa pun jumlah aduan.
import { query } from '../db.js'
import { iso, tanggalSaja, sekarangDb } from '../support/waktu.js'
import { namaPelapor } from '../support/teks.js'

export const STATUS = ['Baru', 'Diproses', 'Monitoring', 'Selesai']

/** Satu-satunya status tujuan yang sah dari tiap status. Urutan wajib: Baru -> Diproses -> Monitoring -> Selesai (tidak boleh melompat atau mundur). */
export const STATUS_BERIKUTNYA = { Baru: 'Diproses', Diproses: 'Monitoring', Monitoring: 'Selesai', Selesai: null }
export const PRIORITAS = ['Urgent', 'Tinggi', 'Sedang', 'Rendah']
export const KATEGORI = ['Gangguan / Error', 'Pengembangan / Update Fitur']

const KOLOM_ADUAN = `a.*, m.nama_modul, p.nama AS pelapor_nama,
  CASE WHEN p.role = 'klien' THEN i.kode_instansi END AS kode_instansi`
const DARI_ADUAN = `FROM aduan a
  LEFT JOIN modul m ON m.id = a.modul_id
  LEFT JOIN pengguna p ON p.id = a.pelapor_id
  LEFT JOIN instansi i ON i.id = p.instansi_id`
// Nomor terbesar dulu: HD-014 sebelum HD-013, HD-1000 sesudah HD-999 karena lebih panjang.
const URUTAN = 'ORDER BY LENGTH(a.kode_aduan) DESC, a.kode_aduan DESC'

/** Muat teknisi, riwayat, dan solusi untuk sekumpulan baris aduan, lalu tempelkan ke tiap baris. */
async function lengkapi(baris, s) {
  if (baris.length === 0) return baris
  const ids = baris.map((b) => b.id)

  const [teknisi, riwayat, solusi] = await Promise.all([
    query(
      `SELECT pt.aduan_id, u.id, u.nama
         FROM penugasan_teknisi pt JOIN pengguna u ON u.id = pt.teknisi_id
        WHERE pt.aduan_id IN (?) ORDER BY pt.id`,
      [ids],
      s,
    ),
    query(
      `SELECT r.aduan_id, r.created_at, r.tindakan, u.role, u.nama
         FROM riwayat_aduan r LEFT JOIN pengguna u ON u.id = r.pengguna_id
        WHERE r.aduan_id IN (?) ORDER BY r.created_at, r.id`,
      [ids],
      s,
    ),
    query('SELECT * FROM solusi_aduan WHERE aduan_id IN (?)', [ids], s),
  ])

  for (const b of baris) {
    b.teknisi = teknisi.filter((t) => t.aduan_id === b.id)
    b.riwayat = riwayat.filter((r) => r.aduan_id === b.id)
    b.solusi = solusi.find((x) => x.aduan_id === b.id) ?? null
  }
  return baris
}

/** Semua aduan (terbaru dulu), boleh disaring. kodeInstansi diisi untuk Klien. */
export async function daftar({ status = null, prioritas = null, kodeInstansi = null } = {}, s) {
  const syarat = []
  const nilai = []
  if (status !== null) { syarat.push('a.status = ?'); nilai.push(status) }
  if (prioritas !== null) { syarat.push('a.prioritas = ?'); nilai.push(prioritas) }
  if (kodeInstansi !== null) { syarat.push("p.role = 'klien' AND i.kode_instansi = ?"); nilai.push(kodeInstansi) }
  const where = syarat.length ? `WHERE ${syarat.join(' AND ')}` : ''
  const baris = await query(`SELECT ${KOLOM_ADUAN} ${DARI_ADUAN} ${where} ${URUTAN}`, nilai, s)
  return lengkapi(baris, s)
}

/** Satu aduan lewat kode (huruf kecil diterima), atau null. */
export async function cariKode(kode, s) {
  const baris = await query(`SELECT ${KOLOM_ADUAN} ${DARI_ADUAN} WHERE a.kode_aduan = ?`, [String(kode).toUpperCase()], s)
  return (await lengkapi(baris, s))[0] ?? null
}

/** Kunci baris aduan selama transaksi (FOR UPDATE), jadi dua permintaan bersamaan tidak saling menimpa. */
export async function kunci(kode, s) {
  await query('SELECT id FROM aduan WHERE kode_aduan = ? FOR UPDATE', [String(kode).toUpperCase()], s)
}

/** Tambah satu baris riwayat. */
export async function catat(aduanId, tindakan, penggunaId, s) {
  const sekarang = sekarangDb()
  await query(
    'INSERT INTO riwayat_aduan (aduan_id, pengguna_id, tindakan, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    [aduanId, penggunaId, tindakan, sekarang, sekarang],
    s,
  )
}

/** Ubah kolom aduan. `ubah` berisi nama kolom dan nilai barunya. */
export async function perbarui(aduanId, ubah, s) {
  const kolom = Object.keys(ubah)
  await query(
    `UPDATE aduan SET ${kolom.map((k) => `${k} = ?`).join(', ')}, updated_at = ? WHERE id = ?`,
    [...Object.values(ubah), sekarangDb(), aduanId],
    s,
  )
}

export async function tugaskan(aduanId, teknisiId, peran, s) {
  const sekarang = sekarangDb()
  await query(
    'INSERT INTO penugasan_teknisi (aduan_id, teknisi_id, peran, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    [aduanId, teknisiId, peran, sekarang, sekarang],
    s,
  )
}

/** Simpan solusi: baru bila belum ada, atau timpa yang lama. Tanggal selesai = sekarang. */
export async function simpanSolusi(aduanId, masalah, solusi, s) {
  const sekarang = sekarangDb()
  const [ada] = await query('SELECT id FROM solusi_aduan WHERE aduan_id = ?', [aduanId], s)
  if (ada) {
    await query(
      'UPDATE solusi_aduan SET masalah = ?, solusi = ?, tanggal_selesai = ?, updated_at = ? WHERE id = ?',
      [masalah, solusi, sekarang, sekarang, ada.id],
      s,
    )
  } else {
    await query(
      'INSERT INTO solusi_aduan (aduan_id, masalah, solusi, tanggal_selesai, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
      [aduanId, masalah, solusi, sekarang, sekarang, sekarang],
      s,
    )
  }
}

/** Modul bawaan. Nama modul di aduan boleh teks bebas (frontend), jadi modul baru dibuat bila belum ada. */
export async function idModul(nama, s) {
  const rapi = String(nama ?? '').trim() || 'Lainnya'
  const [ada] = await query('SELECT id FROM modul WHERE nama_modul = ? LIMIT 1', [rapi], s)
  if (ada) return ada.id
  const sekarang = sekarangDb()
  const hasil = await query('INSERT INTO modul (nama_modul, created_at, updated_at) VALUES (?, ?, ?)', [rapi, sekarang, sekarang], s)
  return hasil.insertId
}

/** Kode berikutnya: HD-014 sudah ada -> HD-015. Semua baris aduan dikunci supaya dua aduan bersamaan tidak kembar. */
async function kodeBerikutnya(s) {
  const kode = await query('SELECT kode_aduan FROM aduan FOR UPDATE', [], s)
  const terbesar = Math.max(0, ...kode.map((k) => Number.parseInt(k.kode_aduan.slice(3), 10) || 0))
  return `HD-${String(terbesar + 1).padStart(3, '0')}`
}

/** Buat aduan baru (status Baru, dikirim klien sendiri). */
export async function buatBaru(data, klienId, s) {
  const kodeBaru = await kodeBerikutnya(s)
  const sekarang = sekarangDb()
  await query(
    `INSERT INTO aduan (kode_aduan, pelapor_id, modul_id, kategori, judul, deskripsi, prioritas, status, lampiran, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'Baru', ?, ?, ?)`,
    [kodeBaru, klienId, data.modulId, data.kategori, data.judul, data.deskripsi, data.prioritas, data.lampiran, sekarang, sekarang],
    s,
  )
  const [baru] = await query('SELECT id FROM aduan WHERE kode_aduan = ?', [kodeBaru], s)
  await catat(baru.id, 'Aduan dikirim', klienId, s)
  return kodeBaru
}

/** Aduan dari telepon: klien menelepon teknisi langsung, teknisi mencatatnya atas nama klien.
 *  Tanpa penugasan admin: teknisi pencatat langsung menjadi teknisi utama dan status langsung Diproses. */
export async function buatDariTelepon(data, klienId, teknisi, s) {
  const kodeBaru = await kodeBerikutnya(s)
  const sekarang = sekarangDb()
  await query(
    `INSERT INTO aduan (kode_aduan, pelapor_id, modul_id, kategori, judul, deskripsi, prioritas, status, kanal, lampiran, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'Diproses', 'telepon', ?, ?, ?)`,
    [kodeBaru, klienId, data.modulId, data.kategori, data.judul, data.deskripsi, data.prioritas, data.lampiran, sekarang, sekarang],
    s,
  )
  const [baru] = await query('SELECT id FROM aduan WHERE kode_aduan = ?', [kodeBaru], s)
  await tugaskan(baru.id, teknisi.id, 'utama', s)
  await catat(baru.id, `Aduan diterima lewat telepon dan dicatat oleh ${teknisi.nama}`, teknisi.id, s)
  await catat(baru.id, `Langsung dikerjakan oleh ${teknisi.nama} (tanpa penugasan admin)`, teknisi.id, s)
  return kodeBaru
}

/** Ubah tanggal selesai solusi menjadi sekarang (dipakai saat admin menyetujui laporan aduan telepon). */
export async function tandaiSolusiSelesai(aduanId, s) {
  const sekarang = sekarangDb()
  await query('UPDATE solusi_aduan SET tanggal_selesai = ?, updated_at = ? WHERE aduan_id = ?', [sekarang, sekarang, aduanId], s)
}

/** Bentuk JSON aduan sesuai kontrak (urutan kunci sama dengan TicketResource).
 *  Alur selesai aduan telepon: Teknisi -> Admin -> Klien. Selama admin belum menyetujui, klien belum melihat solusinya. */
export function keKontrak(a, peran = null) {
  const namaTeknisi = a.teknisi.map((t) => t.nama)
  const menunggu = Boolean(a.menunggu_verifikasi)
  const solusi = menunggu && peran === 'Klien' ? null : a.solusi
  return {
    id: a.kode_aduan,
    title: a.judul,
    category: a.kategori,
    module: a.nama_modul ?? null,
    description: a.deskripsi,
    status: a.status,
    priority: a.prioritas ?? null,
    channel: a.kanal ?? 'web',
    awaitingVerification: menunggu,
    technicians: namaTeknisi,
    attachment: a.lampiran ?? null,
    photos: { before: a.foto_sebelum ?? null, after: a.foto_sesudah ?? null },
    instansiCode: a.kode_instansi ?? null,
    reporter: a.pelapor_nama == null ? null : namaPelapor(a.pelapor_nama),
    rating: a.rating ?? null,
    solution: solusi === null
      ? null
      : {
          part: solusi.masalah,
          summary: solusi.solusi,
          handledBy: namaTeknisi,
          date: tanggalSaja(solusi.tanggal_selesai),
        },
    logs: a.riwayat.map((r) => ({
      at: iso(r.created_at),
      actor: r.role === 'klien' ? 'Klien' : r.role === 'admin' ? 'Admin' : String(r.nama ?? ''),
      message: r.tindakan,
    })),
    createdAt: iso(a.created_at),
  }
}
