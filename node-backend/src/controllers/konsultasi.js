// Konsultasi (tabel konsultasi, pesan_konsultasi, riwayat_konsultasi) dan kuota jadwal (tabel kuota_konsultasi).
// Padanan KonsultasiController dan KuotaKonsultasiController di Laravel. Urutan pemeriksaan:
// body JSON diparse dulu (400), lalu 404 data, lalu validasi isian (422), lalu aturan bisnis (409).
import { GalatHttp, tidakDitemukan } from '../core/http-error.js'
import { transaksi } from '../db.js'
import { maks, bolehKosong, teks, validasi, wajib, panjang } from '../core/validator.js'
import * as pengguna from '../repositories/pengguna.js'
import * as k from '../repositories/konsultasi.js'
import { potong } from '../support/teks.js'
import { tanggalValid } from '../support/waktu.js'
import { akunDariToken, teksRapi } from './dasar.js'

/** Klien hanya melihat konsultasi instansinya (sama dengan aturan aduan). Ubah ke false bila Klien boleh melihat semua. */
const KLIEN_HANYA_INSTANSINYA = true
const batasInstansi = (ctx) =>
  KLIEN_HANYA_INSTANSINYA && ctx.pengguna.role === 'Klien' ? ctx.pengguna.instansiCode : null

/** Jalankan perubahan di dalam transaksi. id yang bukan angka dianggap tidak ada. Balas konsultasi terbaru. */
async function ubahKonsultasi(ctx, kerjakan, status = 200) {
  return transaksi(async (s) => {
    const id = ctx.params.id
    if (!/^\d+$/.test(id)) throw tidakDitemukan('Konsultasi')
    await k.kunci(Number(id), s)
    const konsultasi = await k.cariId(Number(id), s)
    if (!konsultasi) throw tidakDitemukan('Konsultasi')
    await kerjakan(konsultasi, s)
    return { status, body: { data: k.keKontrak(await k.cariId(Number(id), s)) } }
  })
}

/** GET /consultations */
export async function index(ctx) {
  const daftar = await k.daftar(batasInstansi(ctx))
  return { body: { data: daftar.map(k.keKontrak) } }
}

/** POST /consultations : klien mengajukan jadwal. Pelapor dan instansi dari akun, bukan dari body. */
export async function store(ctx) {
  const isi = await ctx.isi()
  validasi(isi, {
    topic: [wajib('Isi topik dulu'), teks('Isi topik dulu'), maks(150, 'Topik maksimal 150 karakter')],
    note: [bolehKosong(), teks('Catatan harus berupa teks'), maks(5000, 'Catatan maksimal 5.000 karakter')],
    date: [wajib('Pilih tanggal dulu'), teks('Pilih tanggal dulu')],
  })
  const tanggal = isi.date.trim()
  if (!tanggalValid(tanggal)) throw new GalatHttp(422, 'VALIDASI', 'Tanggal harus berformat TTTT-BB-HH')
  const jam = teksRapi(isi.time)
  const akun = await akunDariToken(ctx)

  const id = await transaksi(async (s) => {
    // Satu jam, satu antrean: baris konsultasi jam itu dikunci, jadi dua permintaan bersamaan tidak bisa sama-sama lolos.
    const kuota = jam === '' ? null : await k.kuotaJam(jam, tanggal, s)
    if (kuota === null || (await k.jumlahPemakaiKuota(tanggal, jam, s)) >= kuota) {
      throw new GalatHttp(409, 'KUOTA_PENUH', 'Pilih jam yang masih tersedia')
    }
    const topik = isi.topic.trim()
    const catatan = String(isi.note ?? '').trim()
    const baru = await k.buat({ pengajuId: akun.id, topik, catatan: catatan === '' ? null : catatan, tanggal, jam }, s)
    await k.tambahPesan(baru, akun.id, topik + (catatan !== '' ? `. ${catatan}` : ''), s)
    await k.catat(baru, 'Pengguna mengajukan konsultasi', s)
    return baru
  })
  return { status: 201, body: { data: k.keKontrak(await k.cariId(id)) } }
}

/** PATCH /consultations/:id : Admin mengubah status. Terjadwal hanya boleh bila teknisi sudah ditugaskan. */
export async function ubahStatus(ctx) {
  const isi = await ctx.isi()
  return ubahKonsultasi(ctx, async (konsultasi, s) => {
    const status = teksRapi(isi.status)
    if (!k.STATUS_DARI_ADMIN.includes(status)) throw new GalatHttp(422, 'VALIDASI', 'Status tidak valid')
    if (status === 'Terjadwal' && konsultasi.teknisi_id === null) {
      throw new GalatHttp(409, 'TEKNISI_KOSONG', 'Tugaskan teknisi dulu sebelum menyetujui')
    }
    await k.perbarui(konsultasi.id, { status }, s)
    await k.catat(konsultasi.id, `Admin mengubah status menjadi ${status}`, s)
  })
}

/** PUT /consultations/:id/technician : Admin menugaskan satu teknisi (boleh diganti). */
export async function tugaskan(ctx) {
  const isi = await ctx.isi()
  return ubahKonsultasi(ctx, async (konsultasi, s) => {
    const nama = teksRapi(isi.technician)
    if (nama === '') throw new GalatHttp(422, 'VALIDASI', 'Pilih teknisi dulu')
    const teknisi = await pengguna.teknisiBernama(nama, s)
    if (!teknisi) throw tidakDitemukan('Teknisi')
    await k.perbarui(konsultasi.id, { teknisi_id: teknisi.id }, s)
    await k.catat(konsultasi.id, `Admin menugaskan ${teknisi.nama} sebagai teknisi konsultasi`, s)
  })
}

/** POST /consultations/:id/messages : Admin membalas. 201 + konsultasi terbaru. */
export async function balas(ctx) {
  const isi = await ctx.isi()
  return ubahKonsultasi(
    ctx,
    async (konsultasi, s) => {
      const mentah = ['string', 'number', 'boolean'].includes(typeof isi.text) ? (isi.text === false ? '' : isi.text === true ? '1' : String(isi.text)) : ''
      const pesan = mentah.trim()
      if (pesan === '') throw new GalatHttp(422, 'VALIDASI', 'Tulis balasan dulu')
      if (panjang(pesan) > 5000) throw new GalatHttp(422, 'VALIDASI', 'Balasan maksimal 5.000 karakter')
      await k.tambahPesan(konsultasi.id, Number(ctx.pengguna.id), pesan, s)
      // Riwayat memotong balasan panjang di 28 karakter (sama dengan server tiruan).
      const ringkas = panjang(pesan) > 28 ? `${potong(pesan, 0, 28)}…` : pesan
      await k.catat(konsultasi.id, `Admin membalas: ${ringkas}`, s)
    },
    201,
  )
}

// ---- Kuota jadwal ----

const PESAN_KUOTA = 'Kuota harus bilangan bulat 0 sampai 20'
const adalahWadah = (nilai) => nilai !== null && typeof nilai === 'object'   // objek atau larik

/** GET /consultation-quota */
export async function lihatKuota() {
  return { body: { data: await k.semuaKuota() } }
}

/** PUT /consultation-quota : defaults wajib memuat keenam jam, overrides boleh kosong. Mengganti seluruh kuota. */
export async function simpanKuota(ctx) {
  const isi = await ctx.isi()
  const defaults = isi.defaults ?? {}
  const overrides = isi.overrides ?? {}
  if (!adalahWadah(defaults) || !adalahWadah(overrides)) throw new GalatHttp(422, 'VALIDASI', PESAN_KUOTA)

  for (const jam of k.JAM) {
    if (!k.kuotaValid(defaults[jam])) throw new GalatHttp(422, 'VALIDASI', PESAN_KUOTA)
  }
  for (const [kunci, kuota] of Object.entries(overrides)) {
    if (!k.kuotaValid(kuota)) throw new GalatHttp(422, 'VALIDASI', PESAN_KUOTA)
    if (!k.kunciValid(kunci)) {
      throw new GalatHttp(422, 'VALIDASI', 'Kuota khusus harus memakai kunci TTTT-BB-HH|JJ.MM, contoh 2026-10-08|10.00')
    }
  }
  await transaksi((s) => k.simpanKuota(defaults, overrides, Number(ctx.pengguna.id), s))
  return { body: { data: await k.semuaKuota() } }
}
