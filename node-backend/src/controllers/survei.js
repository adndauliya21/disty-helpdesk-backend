// Survei kepuasan (tabel survei), layar Survei milik Klien. Padanan SurveiController di Laravel.
//   GET /surveys/me : { data: { overall, aspects, resolved, comment, submittedAt } } atau { data: null }
//   PUT /surveys/me : Klien { overall, aspects, resolved, comment }. Satu survei per akun: kirim ulang menimpa survei lama.
import { GalatHttp } from '../core/http-error.js'
import { panjang } from '../core/validator.js'
import * as survei from '../repositories/survei.js'
import { akunDariToken } from './dasar.js'

const MAKS_RESOLVED = 30
const MAKS_KOMENTAR = 2000

/** Bilangan bulat sungguhan (bukan teks "4" atau true) dalam rentang. */
const bilanganBulat = (nilai, min, maks) => Number.isInteger(nilai) && nilai >= min && nilai <= maks

/** Teks opsional: tidak diisi atau null -> teks kosong. Bukan teks atau terlalu panjang -> 422. Spasi di tepi dibuang. */
function teksOpsional(nilai, maks, pesan) {
  if (nilai === null || nilai === undefined) return ''
  if (typeof nilai !== 'string' || panjang(nilai.trim()) > maks) throw new GalatHttp(422, 'VALIDASI', pesan)
  return nilai.trim()
}

const wadahKosong = (nilai) =>
  nilai === null || nilai === undefined || nilai === false
  || (Array.isArray(nilai) && nilai.length === 0)
  || (typeof nilai === 'object' && !Array.isArray(nilai) && Object.keys(nilai).length === 0)

/** GET /surveys/me */
export async function lihat(ctx) {
  const ada = ctx.pengguna.role === 'Klien' ? await survei.terakhirDari(Number(ctx.pengguna.id)) : null
  return { body: { data: ada ? survei.keKontrak(ada) : null } }
}

/** PUT /surveys/me */
export async function simpan(ctx) {
  const isi = await ctx.isi()               // 400 bila bukan objek JSON
  const akun = await akunDariToken(ctx)     // 401 bila akun sudah dihapus

  if (!bilanganBulat(isi.overall, 1, 5)) throw new GalatHttp(422, 'VALIDASI', 'Pilih penilaian keseluruhan dulu')

  // aspects tidak diisi (atau kosong) -> [0, 0, 0] (belum dinilai)
  let aspek = isi.aspects
  if (wadahKosong(aspek)) aspek = [0, 0, 0]
  const pesanAspek = 'Nilai aspek harus tiga bilangan bulat 0 sampai 5'
  if (!Array.isArray(aspek) || aspek.length !== 3) throw new GalatHttp(422, 'VALIDASI', pesanAspek)
  for (const nilai of aspek) {
    if (!bilanganBulat(nilai, 0, 5)) throw new GalatHttp(422, 'VALIDASI', pesanAspek)
  }

  const pilihan = teksOpsional(isi.resolved, MAKS_RESOLVED, 'Pilihan terselesaikan tidak valid')
  const terselesaikan = survei.dariPilihan(pilihan)
  if (terselesaikan === null) throw new GalatHttp(422, 'VALIDASI', 'Pilihan terselesaikan tidak valid')
  const komentar = teksOpsional(isi.comment, MAKS_KOMENTAR, `Komentar maksimal ${MAKS_KOMENTAR} karakter`)

  await survei.simpan(akun.id, { overall: isi.overall, aspek, terselesaikan, komentar: komentar === '' ? null : komentar })
  return { body: { data: survei.keKontrak(await survei.terakhirDari(akun.id)) } }
}
