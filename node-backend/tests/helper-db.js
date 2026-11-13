// Pembantu tes integrasi: menyiapkan database KHUSUS tes dari database/disty_helpdesk.sql lalu menyalakan server sungguhan.
// File SQL berisi "DROP DATABASE disty_helpdesk", jadi namanya diganti ke database tes dan helper ini menolak berjalan
// bila nama database tes sama dengan database pengembangan. Data pengembanganmu tidak akan tersentuh.
import { readFileSync } from 'node:fs'
import { createServer } from 'node:http'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import mysql from 'mysql2/promise'

const folder = path.dirname(fileURLToPath(import.meta.url))
const berkasSql = path.resolve(folder, '../../database/disty_helpdesk.sql')

export const NAMA_DB_TES = process.env.TEST_DB_DATABASE || 'disty_helpdesk_test'
const DB_PENGEMBANGAN = 'disty_helpdesk'

/** Pecah isi file SQL menjadi perintah satuan. Mengenali DELIMITER, teks bertanda kutip, dan komentar. */
export function pecahSql(sql) {
  const hasil = []
  let pembatas = ';'
  let sekarang = ''
  let i = 0
  const awalBaris = () => i === 0 || sql[i - 1] === '\n'
  while (i < sql.length) {
    if (awalBaris() && /^DELIMITER\s+(\S+)/i.test(sql.slice(i, i + 40))) {
      const [baris, baru] = /^DELIMITER\s+(\S+)[^\n]*/i.exec(sql.slice(i))
      pembatas = baru
      i += baris.length
      continue
    }
    if (sql.startsWith('--', i) || sql[i] === '#') {
      while (i < sql.length && sql[i] !== '\n') i++
      continue
    }
    if (sql.startsWith('/*', i)) {
      const akhir = sql.indexOf('*/', i + 2)
      i = akhir === -1 ? sql.length : akhir + 2
      continue
    }
    const c = sql[i]
    if (c === "'" || c === '"' || c === '`') {
      let j = i + 1
      while (j < sql.length) {
        if (sql[j] === '\\' && c !== '`') { j += 2; continue }
        if (sql[j] === c) {
          if (sql[j + 1] === c) { j += 2; continue }   // kutip ganda di dalam teks
          break
        }
        j++
      }
      sekarang += sql.slice(i, j + 1)
      i = j + 1
      continue
    }
    if (sql.startsWith(pembatas, i)) {
      if (sekarang.trim()) hasil.push(sekarang.trim())
      sekarang = ''
      i += pembatas.length
      continue
    }
    sekarang += c
    i++
  }
  if (sekarang.trim()) hasil.push(sekarang.trim())
  return hasil
}

/** Buat ulang database tes dari file SQL. */
export async function siapkanDatabaseTes(env = process.env) {
  if (NAMA_DB_TES === DB_PENGEMBANGAN) {
    throw new Error(`TEST_DB_DATABASE tidak boleh sama dengan database pengembangan (${DB_PENGEMBANGAN})`)
  }
  const sambungan = await mysql.createConnection({
    host: env.DB_HOST ?? '127.0.0.1',
    port: Number(env.DB_PORT ?? 3306),
    user: env.DB_USERNAME ?? 'root',
    password: env.DB_PASSWORD ?? '',
  })
  try {
    const sql = readFileSync(berkasSql, 'utf8').replaceAll(DB_PENGEMBANGAN, NAMA_DB_TES)
    for (const perintah of pecahSql(sql)) await sambungan.query(perintah)
  } finally {
    await sambungan.end()
  }
}

/** Nyalakan server di port acak. Mengembalikan { url, tutup, masuk(email), api(metode, path, opsi) }. */
export async function nyalakanServer() {
  process.env.DB_DATABASE = NAMA_DB_TES
  process.env.BCRYPT_ROUNDS = '4'
  process.env.JWT_SECRET = 'rahasia-tes-otomatis'
  await siapkanDatabaseTes()
  const { buatPenangan } = await import('../src/app.js')
  const { pool } = await import('../src/db.js')

  const server = createServer(buatPenangan())
  await new Promise((selesai) => server.listen(0, '127.0.0.1', selesai))
  const url = `http://127.0.0.1:${server.address().port}`
  const token = {}

  async function api(metode, jalur, { akun, body, raw, headers = {}, form } = {}) {
    const h = { Accept: 'application/json', ...headers }
    if (akun) h.Authorization = `Bearer ${token[akun]}`
    let isi
    if (form) isi = form
    else if (raw !== undefined) { isi = raw; h['Content-Type'] = 'application/json' }
    else if (body !== undefined) { isi = JSON.stringify(body); h['Content-Type'] = 'application/json' }
    const res = await fetch(`${url}/api/v1${jalur}`, { method: metode, headers: h, body: isi })
    const teks = await res.text()
    let json = null
    try { json = JSON.parse(teks) } catch { /* bukan JSON */ }
    return { status: res.status, json, headers: res.headers, teks }
  }

  async function masuk(nama, email, sandi = 'rahasia123') {
    const r = await api('POST', '/auth/login', { body: { email, password: sandi } })
    if (r.status !== 200) throw new Error(`login ${nama} gagal: ${r.teks}`)
    token[nama] = r.json.data.token
  }

  return {
    url,
    api,
    masuk,
    token,
    pool,
    tutup: async () => {
      await new Promise((selesai) => server.close(selesai))
      await pool.end()
    },
  }
}

export const AKUN = {
  admin: 'admin@perusahaan.id',
  rafi: 'rafi@perusahaan.id',
  dina: 'dina@perusahaan.id',
  bayu: 'bayu@perusahaan.id',
  rina: 'rina@smkn2.sch.id',
  yusuf: 'yusuf@sman5sby.sch.id',
  citra: 'citra@smkn1mlg.sch.id',
  hesti: 'hesti@man1mlg.sch.id',
}
