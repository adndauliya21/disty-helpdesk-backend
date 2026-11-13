// Koneksi MySQL (mysql2/promise). Semua query memakai tanda tanya (?) supaya nilai tidak pernah
// disambung ke teks SQL, jadi aman dari SQL injection.
// Waktu: sesi MySQL dipaksa UTC dan kolom tanggal dibaca sebagai teks, sama dengan Laravel (APP_TIMEZONE=UTC).
import mysql from 'mysql2/promise'
import { config } from './config.js'

export const pool = mysql.createPool({
  ...config.db,
  waitForConnections: true,
  connectionLimit: 10,
  charset: 'utf8mb4',
  dateStrings: true,
  timezone: 'Z',
})

pool.pool.on('connection', (koneksi) => {
  koneksi.query("SET time_zone = '+00:00'")
})

/** Jalankan satu query. Mengembalikan array baris (SELECT) atau objek hasil (INSERT/UPDATE/DELETE). */
export async function query(sql, nilai = [], sambungan = pool) {
  const [hasil] = await sambungan.query(sql, nilai)
  return hasil
}

/** Jalankan fungsi di dalam satu transaksi. Fungsi menerima koneksi, lalu dikomit bila selesai atau di-rollback bila gagal. */
export async function transaksi(kerjakan) {
  const sambungan = await pool.getConnection()
  try {
    await sambungan.beginTransaction()
    const hasil = await kerjakan(sambungan)
    await sambungan.commit()
    return hasil
  } catch (galat) {
    await sambungan.rollback().catch(() => {})
    throw galat
  } finally {
    sambungan.release()
  }
}
