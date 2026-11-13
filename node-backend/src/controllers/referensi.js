// Data referensi (GET /knowledge-base, /solutions, /institutions) dan GET /ping. Semua dibaca dari database.
// Padanan ReferensiController dan InfoController di Laravel.
import { query } from '../db.js'
import { tidakDitemukan } from '../core/http-error.js'
import { iso, jamWib, sekarangDb, tanggalWib } from '../support/waktu.js'
import { namaPelapor } from '../support/teks.js'

/** GET /ping : memastikan server hidup. Terbuka tanpa token. */
export const ping = () => ({
  body: { data: { status: 'ok', service: 'Disty HelpDesk API', node: process.version, time: iso(sekarangDb()) } },
})

/** GET /knowledge-base : { "Database": ["judul", ...], ... } dengan urutan asli. Kosong tetap objek {}. */
export async function basisPengetahuan() {
  const baris = await query(
    'SELECT m.nama_modul, a.judul FROM artikel_bantuan a JOIN modul m ON m.id = a.modul_id ORDER BY a.id',
  )
  const hasil = {}
  for (const { nama_modul: modul, judul } of baris) (hasil[modul] ??= []).push(judul)
  return { body: { data: hasil } }
}

/** GET /knowledge-base/:judul : isi lengkap satu artikel dari tabel artikel_bantuan. */
export async function artikel(ctx) {
  const [baris] = await query(
    'SELECT m.nama_modul, a.judul, a.isi FROM artikel_bantuan a JOIN modul m ON m.id = a.modul_id WHERE a.judul = ? LIMIT 1',
    [ctx.params.judul],
  )
  if (!baris) throw tidakDitemukan('Artikel')
  return { body: { data: { title: baris.judul, module: baris.nama_modul, content: baris.isi } } }
}

/** GET /solutions : sengaja kosong. Solusi penanganan sekarang ada di tabel solusi_aduan dan sudah ikut di setiap aduan
 *  (field solution di GET /tickets). Frontend menggabungkan keduanya, jadi di sini kosong supaya solusi tidak tampil dobel. */
export const solusi = () => ({ body: { data: [] } })

/** GET /institutions : instansi dan klien terdaftar. Bentuk sama dengan INST di frontend. Riwayat aduan dan konsultasi
 *  per pelapor tidak ikut dikirim (h dan k kosong), karena frontend menggabungkannya dari GET /tickets dan /consultations. */
export async function instansi() {
  const [daftarInstansi, klien, survei] = await Promise.all([
    query('SELECT * FROM instansi ORDER BY id'),
    query("SELECT * FROM pengguna WHERE role = 'klien' ORDER BY id"),
    query(
      `SELECT s.pengguna_id, s.nilai_keseluruhan, s.komentar, a.kode_aduan
         FROM survei s LEFT JOIN aduan a ON a.id = s.aduan_id ORDER BY s.id`,
    ),
  ])
  const hasil = {}
  for (const i of daftarInstansi) {
    hasil[i.kode_instansi] = {
      n: i.nama_instansi,
      dom: i.domain_email,
      p: klien
        .filter((k) => k.instansi_id === i.id)
        .map((k) => ({
          n: namaPelapor(k.nama),
          j: 'Klien',
          e: k.email,
          tel: String(k.telepon ?? ''),
          g: tanggalWib(k.created_at),
          login: k.last_login ? `${tanggalWib(k.last_login)}, ${jamWib(k.last_login)}` : '-',
          last: k.last_login ? tanggalWib(k.last_login) : '-',
          h: [],
          k: [],
          v: survei
            .filter((s) => s.pengguna_id === k.id)
            .map((s) => [s.kode_aduan ?? '-', s.nilai_keseluruhan, String(s.komentar ?? '')]),
          act: [],
        })),
    }
  }
  return { body: { data: hasil } }
}
