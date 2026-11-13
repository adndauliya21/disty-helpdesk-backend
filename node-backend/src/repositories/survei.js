// Akses tabel survei. Padanan model Survei di Laravel.
// terselesaikan di database: Ya | Sebagian | Belum. Frontend memakai teks pilihan "Ya, sudah".
import { query } from '../db.js'
import { iso, sekarangDb } from '../support/waktu.js'

/** Survei terakhir milik seorang klien, atau null. */
export async function terakhirDari(penggunaId, s) {
  const [survei] = await query('SELECT * FROM survei WHERE pengguna_id = ? ORDER BY id DESC LIMIT 1', [penggunaId], s)
  return survei ?? null
}

/** Pilihan dari frontend ("Ya, sudah", "Sebagian", "Belum", atau kosong) menjadi nilai ENUM. Kosong dihitung Belum. */
export function dariPilihan(pilihan) {
  switch (pilihan.trim().toLowerCase()) {
    case '':
    case 'belum':
      return 'Belum'
    case 'ya':
    case 'ya, sudah':
      return 'Ya'
    case 'sebagian':
      return 'Sebagian'
    default:
      return null
  }
}

/** Simpan survei: perbarui baris terakhir milik klien bila ada, kalau tidak buat baru. updated_at selalu diperbarui. */
export async function simpan(penggunaId, nilai, s) {
  const sekarang = sekarangDb()
  const ada = await terakhirDari(penggunaId, s)
  const kolom = [nilai.overall, nilai.aspek[0], nilai.aspek[1], nilai.aspek[2], nilai.terselesaikan, nilai.komentar]
  if (ada) {
    await query(
      `UPDATE survei SET nilai_keseluruhan = ?, nilai_respon = ?, nilai_kemudahan = ?, nilai_kualitas = ?,
              terselesaikan = ?, komentar = ?, updated_at = ? WHERE id = ?`,
      [...kolom, sekarang, ada.id],
      s,
    )
    return ada.id
  }
  const hasil = await query(
    `INSERT INTO survei (pengguna_id, nilai_keseluruhan, nilai_respon, nilai_kemudahan, nilai_kualitas,
                         terselesaikan, komentar, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [penggunaId, ...kolom, sekarang, sekarang],
    s,
  )
  return hasil.insertId
}

/** Bentuk JSON survei sesuai kontrak. */
export function keKontrak(survei) {
  return {
    overall: survei.nilai_keseluruhan,
    aspects: [survei.nilai_respon, survei.nilai_kemudahan, survei.nilai_kualitas],
    resolved: survei.terselesaikan === 'Ya' ? 'Ya, sudah' : String(survei.terselesaikan),
    comment: String(survei.komentar ?? ''),
    submittedAt: iso(survei.updated_at ?? survei.created_at),
  }
}
