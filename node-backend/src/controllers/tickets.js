// Endpoint aduan (path tetap /tickets sesuai kontrak frontend, datanya di tabel aduan) dan alur penanganannya.
// Padanan TicketController dan AlurAduanController di Laravel. Aturan akses (peran) ada di src/routes.js:
//   - Klien hanya melihat aduan milik instansinya. Aduan instansi lain dijawab 404 (bukan 403), supaya keberadaannya tidak bocor.
//   - Admin dan Teknisi melihat semua aduan.
// Setiap perubahan dikerjakan dalam satu transaksi dengan baris aduan dikunci (FOR UPDATE), jadi dua permintaan
// bersamaan tidak saling menimpa dan riwayat tidak setengah tersimpan.
import { GalatHttp, tidakDitemukan } from '../core/http-error.js'
import { transaksi } from '../db.js'
import { antara, bolehKosong, bulat, enumDariQuery, maks, teks, validasi, wajib, larik, minimal } from '../core/validator.js'
import * as aduan from '../repositories/aduan.js'
import * as pengguna from '../repositories/pengguna.js'
import { periksaUrlFoto } from '../support/url-foto.js'
import { akunDariToken, batasInstansi, teksRapi } from './dasar.js'

const dalamDaftar = (daftar, pesan) => ({ cek: (v) => typeof v === 'string' && daftar.includes(v), pesan })
// Aturan isian aduan (dipakai POST /tickets dan POST /tickets/phone).
const ATURAN_ISIAN = {
  description: [wajib('Isi cerita singkat dulu'), teks('Isi cerita singkat dulu'), maks(5000, 'Cerita singkat maksimal 5.000 karakter')],
  title: [wajib('Judul aduan wajib diisi'), teks('Judul aduan wajib diisi'), maks(100, 'Judul aduan maksimal 100 karakter')],
  category: [wajib('Jenis bantuan tidak dikenal'), dalamDaftar(aduan.KATEGORI, 'Jenis bantuan tidak dikenal')],
  priority: [bolehKosong(), dalamDaftar(aduan.PRIORITAS, 'Prioritas tidak valid')],
  module: [bolehKosong(), teks('Modul harus berupa teks'), maks(50, 'Nama modul maksimal 50 karakter')],
  attachment: [bolehKosong(), teks('Lampiran harus berupa teks'), maks(255, 'Alamat lampiran terlalu panjang')],
}
const KOLOM_FOTO = { before: 'foto_sebelum', after: 'foto_sesudah' }

// ---- Membaca ----

/** GET /tickets : semua aduan. Boleh disaring dengan ?status= dan ?priority= */
export async function index(ctx) {
  const status = enumDariQuery(ctx.query, 'status', aduan.STATUS, 'Status tidak valid')
  const prioritas = enumDariQuery(ctx.query, 'priority', aduan.PRIORITAS, 'Prioritas tidak valid')
  const daftar = await aduan.daftar({ status, prioritas, kodeInstansi: batasInstansi(ctx) })
  return { body: { data: daftar.map((a) => aduan.keKontrak(a, ctx.pengguna.role)) } }
}

/** GET /tickets/:id : id = kode aduan, mis. HD-014 */
export async function show(ctx) {
  const batas = batasInstansi(ctx)
  const a = await aduan.cariKode(ctx.params.id)
  if (!a || (batas !== null && a.kode_instansi !== batas)) throw tidakDitemukan('Aduan')
  return { body: { data: aduan.keKontrak(a, ctx.pengguna.role) } }
}

/** POST /tickets : validasi isian, buat aduan baru, balas 201 + header Location. Prioritas boleh kosong sampai admin menetapkannya. */
export async function store(ctx) {
  const isi = await ctx.isi()
  validasi(isi, ATURAN_ISIAN)
  // Pelapor dibaca dari database (bukan dari isi token) supaya nama hasil PUT /me langsung terpakai.
  const akun = await akunDariToken(ctx)
  const kode = await transaksi(async (s) => {
    return aduan.buatBaru(
      {
        judul: isi.title.trim(),
        kategori: isi.category.trim(),
        modulId: await aduan.idModul(isi.module, s),
        deskripsi: isi.description.trim(),
        prioritas: teksRapi(isi.priority) || null,
        lampiran: isi.attachment ?? null,
      },
      akun.id,
      s,
    )
  })
  return { status: 201, headers: { Location: `/api/v1/tickets/${kode}` }, body: { data: aduan.keKontrak(await aduan.cariKode(kode), ctx.pengguna.role) } }
}

/** POST /tickets/phone (Teknisi): klien menelepon teknisi langsung, teknisi mencatat aduannya atas nama klien.
 *  Body: isian aduan biasa + { reporterEmail } (email akun klien yang menelepon). Tanpa admin: teknisi pencatat otomatis
 *  menjadi teknisi utama dan status langsung Diproses. Teknisi hanya memilih klien berakun aktif; ia tidak masuk ke akun siapa pun. */
export async function storeTelepon(ctx) {
  const isi = await ctx.isi()
  validasi(isi, {
    reporterEmail: [wajib('Pilih klien yang menelepon'), teks('Pilih klien yang menelepon'), maks(255, 'Email klien terlalu panjang')],
    ...ATURAN_ISIAN,
  })
  const klien = await pengguna.cariEmail(isi.reporterEmail.trim().toLowerCase())
  if (!klien || klien.role !== 'klien') throw tidakDitemukan('Klien')
  if (klien.status !== 'aktif') {
    throw new GalatHttp(409, 'AKUN_BELUM_AKTIF', `Akun ${pengguna.pelapor(klien.nama)} belum aktif, aduan belum bisa dicatat atas namanya`)
  }
  const teknisi = await akunDariToken(ctx)
  const kode = await transaksi(async (s) => {
    return aduan.buatDariTelepon(
      {
        judul: isi.title.trim(),
        kategori: isi.category.trim(),
        modulId: await aduan.idModul(isi.module, s),
        deskripsi: isi.description.trim(),
        prioritas: teksRapi(isi.priority) || null,
        lampiran: isi.attachment ?? null,
      },
      klien.id,
      teknisi,
      s,
    )
  })
  return { status: 201, headers: { Location: `/api/v1/tickets/${kode}` }, body: { data: aduan.keKontrak(await aduan.cariKode(kode), ctx.pengguna.role) } }
}

// ---- Alur penanganan ----

/** Jalankan perubahan di dalam transaksi: kunci aduan, cari (404 bila tidak ada), kerjakan, lalu balas aduan terbaru. */
async function ubahAduan(ctx, kerjakan, { batasInstansi: batas = null, status = 200 } = {}) {
  return transaksi(async (s) => {
    await aduan.kunci(ctx.params.id, s)
    const a = await aduan.cariKode(ctx.params.id, s)
    if (!a || (batas !== null && a.kode_instansi !== batas)) throw tidakDitemukan('Aduan')
    await kerjakan(a, s)
    return { status, body: { data: aduan.keKontrak(await aduan.cariKode(ctx.params.id, s), ctx.pengguna.role) } }
  })
}

/** Urutan status wajib dipatuhi: Baru -> Diproses -> Monitoring -> Selesai. Melompat (mis. Diproses -> Selesai) atau mundur ditolak 409. */
function pastikanUrutan(a, tujuan) {
  if (aduan.STATUS_BERIKUTNYA[a.status] !== tujuan) {
    throw new GalatHttp(409, 'URUTAN_STATUS_TIDAK_VALID',
      `Tidak diizinkan: urutan status Diproses -> Monitoring -> Selesai harus dipatuhi (status sekarang ${a.status})`)
  }
}

/** PATCH /tickets/:id : { priority } oleh Admin, atau { status, note } oleh Teknisi (note = catatan tahapan, wajib). Pemeriksaan dulu semua, baru ubah. */
export async function ubah(ctx) {
  const isi = await ctx.isi()
  const { role, id: penggunaId } = ctx.pengguna
  return ubahAduan(ctx, async (a, s) => {
    let prioritas = null
    let status = null
    let catatan = ''
    if (Object.hasOwn(isi, 'priority')) {
      if (role !== 'Admin') throw new GalatHttp(403, 'DILARANG', 'Hanya admin yang boleh menetapkan prioritas')
      prioritas = teksRapi(isi.priority)
      if (!aduan.PRIORITAS.includes(prioritas)) throw new GalatHttp(422, 'VALIDASI', 'Prioritas tidak valid')
    }
    if (Object.hasOwn(isi, 'status')) {
      if (role !== 'Teknisi') throw new GalatHttp(403, 'DILARANG', 'Hanya teknisi yang boleh mengubah status')
      status = teksRapi(isi.status)
      if (!aduan.STATUS.includes(status)) throw new GalatHttp(422, 'VALIDASI', 'Status tidak valid')
      pastikanUrutan(a, status)
      if (status === 'Selesai' && a.kanal === 'telepon') {
        throw new GalatHttp(409, 'PERLU_VERIFIKASI_ADMIN', 'Aduan telepon baru selesai setelah laporan pengerjaannya disetujui admin')
      }
      if (status === 'Selesai' && a.solusi === null) {
        throw new GalatHttp(409, 'SOLUSI_BELUM_ADA', 'Isi solusi penanganan dulu, lalu klik Tandai selesai')
      }
      catatan = teksRapi(isi.note)
      if (catatan === '') throw new GalatHttp(422, 'VALIDASI', 'Isi catatan tahapan dulu')
      if ([...catatan].length > 5000) throw new GalatHttp(422, 'VALIDASI', 'Catatan tahapan maksimal 5.000 karakter')
    }
    if (prioritas === null && status === null) throw new GalatHttp(422, 'VALIDASI', 'Isi prioritas atau status yang ingin diubah')

    if (prioritas !== null) {
      await aduan.perbarui(a.id, { prioritas }, s)
      await aduan.catat(a.id, `Prioritas ditetapkan: ${prioritas}`, Number(penggunaId), s)
    }
    if (status !== null) {
      await aduan.catat(a.id, catatan, Number(penggunaId), s) // catatan tahapan dulu, lalu status (sama seperti solusi lalu status Selesai)
      await aduan.perbarui(a.id, { status }, s)
      await aduan.catat(a.id, `Status diubah: ${status}`, Number(penggunaId), s)
    }
  })
}

/** POST /tickets/:id/assignments : tugaskan teknisi. Teknisi pertama (peran utama) otomatis mengubah status jadi Diproses. */
export async function tugaskan(ctx) {
  const isi = await ctx.isi()
  return ubahAduan(ctx, async (a, s) => {
    const nama = teksRapi(isi.technician)
    if (nama === '') throw new GalatHttp(422, 'VALIDASI', 'Pilih teknisi dulu')
    const teknisi = await pengguna.teknisiBernama(nama, s)
    if (!teknisi) throw tidakDitemukan('Teknisi')
    if (a.teknisi.some((t) => t.id === teknisi.id)) {
      throw new GalatHttp(409, 'SUDAH_DITUGASKAN', `${teknisi.nama} sudah ditugaskan pada aduan ini`)
    }
    const pertama = a.teknisi.length === 0
    await aduan.tugaskan(a.id, teknisi.id, pertama ? 'utama' : 'pendamping', s)
    if (pertama) await aduan.perbarui(a.id, { status: 'Diproses' }, s)
    await aduan.catat(
      a.id,
      pertama ? `Aduan diteruskan ke ${teknisi.nama}` : `${teknisi.nama} ditambahkan sebagai teknisi pendamping`,
      Number(ctx.pengguna.id),
      s,
    )
  })
}

/** POST /tickets/:id/logs : catatan tindakan teknisi. 201 + aduan terbaru. */
export async function catatTindakan(ctx) {
  const isi = await ctx.isi()
  return ubahAduan(
    ctx,
    async (a, s) => {
      validasi(isi, {
        message: [wajib('Isi tindakan dulu'), teks('Isi tindakan dulu'), maks(5000, 'Catatan tindakan maksimal 5.000 karakter')],
      })
      await aduan.catat(a.id, isi.message.trim(), Number(ctx.pengguna.id), s)
    },
    { status: 201 },
  )
}

/** PUT /tickets/:id/solution : simpan solusi dan tandai Selesai (hanya dari status Monitoring). Dua catatan riwayat: solusi, lalu status.
 *  Penangan yang belum ditugaskan ditambahkan sebagai pendamping, dan handledBy di JSON = semua teknisi aduan itu. */
export async function selesaikan(ctx) {
  const isi = await ctx.isi()
  return ubahAduan(ctx, async (a, s) => {
    validasi(isi, {
      part: [wajib('Pilih bagian yang diperbaiki'), teks('Pilih bagian yang diperbaiki'), maks(150, 'Bagian yang diperbaiki maksimal 150 karakter')],
      summary: [wajib('Isi solusi penanganan'), teks('Isi solusi penanganan'), maks(5000, 'Solusi penanganan maksimal 5.000 karakter')],
      handledBy: [wajib('Pilih teknisi yang menangani'), larik('Pilih teknisi yang menangani'), minimal(1, 'Pilih teknisi yang menangani')],
      'handledBy.*': [wajib('Pilih teknisi yang menangani'), teks('Pilih teknisi yang menangani'), maks(60, 'Nama teknisi maksimal 60 karakter')],
    })
    if (a.status === 'Selesai') throw new GalatHttp(409, 'SUDAH_SELESAI', 'Aduan ini sudah selesai')
    if (a.menunggu_verifikasi) {
      throw new GalatHttp(409, 'MENUNGGU_VERIFIKASI', 'Laporan pengerjaan sudah dikirim dan sedang menunggu pemeriksaan admin')
    }
    pastikanUrutan(a, 'Selesai')

    // Cocokkan nama dari body ke teknisi di database (urutan sesuai body, tanpa duplikat).
    const diminta = [...new Set(isi.handledBy.map((n) => n.trim()))]
    const ada = await pengguna.teknisiBernamaDi(diminta, s)
    const teknisi = new Map()
    const tidakDikenal = []
    for (const nama of diminta) {
      const cocok = ada.find((t) => t.nama.toLowerCase() === nama.toLowerCase())
      if (cocok) teknisi.set(cocok.id, cocok)
      else tidakDikenal.push(nama)
    }
    if (tidakDikenal.length > 0) throw new GalatHttp(422, 'VALIDASI', `Teknisi tidak dikenal: ${tidakDikenal.join(', ')}`)

    // Penangan yang belum tercatat ditambahkan (utama bila aduan belum punya teknisi sama sekali).
    const sudahAda = a.teknisi.map((t) => t.id)
    for (const idTeknisi of teknisi.keys()) {
      if (sudahAda.includes(idTeknisi)) continue
      await aduan.tugaskan(a.id, idTeknisi, sudahAda.length === 0 ? 'utama' : 'pendamping', s)
      sudahAda.push(idTeknisi)
    }
    const terkini = await aduan.cariKode(ctx.params.id, s)
    const bagian = isi.part.trim()
    const ringkasan = isi.summary.trim()
    await aduan.simpanSolusi(a.id, bagian, ringkasan, s)
    const nama = terkini.teknisi.map((t) => t.nama).join(', ')
    // Aduan telepon: Teknisi -> Admin -> Klien. Laporan dikirim ke admin dulu; status tetap Monitoring dan teks solusi belum masuk
    // riwayat (riwayat terlihat klien). Keduanya baru terjadi saat admin menyetujui (verifikasi).
    if (a.kanal === 'telepon') {
      await aduan.perbarui(a.id, { menunggu_verifikasi: 1 }, s)
      await aduan.catat(a.id, `Laporan pengerjaan dikirim ke admin untuk diperiksa (ditangani oleh ${nama})`, Number(ctx.pengguna.id), s)
      return
    }
    await aduan.catat(a.id, `Solusi: ${bagian}. ${ringkasan} (ditangani oleh ${nama})`, Number(ctx.pengguna.id), s)
    await aduan.perbarui(a.id, { status: 'Selesai' }, s)
    await aduan.catat(a.id, 'Status diubah: Selesai', Number(ctx.pengguna.id), s)
  })
}

/** PUT /tickets/:id/verification (Admin): periksa laporan pengerjaan aduan telepon. Body { approve: true|false, note? }.
 *  approve = true  -> status Selesai, solusi masuk riwayat dan terlihat klien (Teknisi -> Admin -> Klien).
 *  approve = false -> dikembalikan ke teknisi (note wajib) untuk diperbaiki lalu dikirim ulang. */
export async function verifikasi(ctx) {
  const isi = await ctx.isi()
  return ubahAduan(ctx, async (a, s) => {
    if (typeof isi.approve !== 'boolean') throw new GalatHttp(422, 'VALIDASI', 'Pilih setujui atau kembalikan')
    const catatan = teksRapi(isi.note)
    if ([...catatan].length > 5000) throw new GalatHttp(422, 'VALIDASI', 'Catatan maksimal 5.000 karakter')
    if (a.kanal !== 'telepon' || !a.menunggu_verifikasi || a.solusi === null) {
      throw new GalatHttp(409, 'TIDAK_MENUNGGU_VERIFIKASI', 'Aduan ini tidak sedang menunggu pemeriksaan admin')
    }
    const adminId = Number(ctx.pengguna.id)

    if (!isi.approve) {
      if (catatan === '') throw new GalatHttp(422, 'VALIDASI', 'Tulis alasan pengembalian untuk teknisi')
      await aduan.perbarui(a.id, { menunggu_verifikasi: 0 }, s)
      await aduan.catat(a.id, `Admin mengembalikan laporan pengerjaan ke teknisi: ${catatan}`, adminId, s)
      return
    }

    const nama = a.teknisi.map((t) => t.nama).join(', ')
    const penulis = a.teknisi[0]?.id ?? adminId   // solusi ditulis teknisi, bukan admin
    await aduan.tandaiSolusiSelesai(a.id, s)
    await aduan.catat(a.id, `Solusi: ${a.solusi.masalah}. ${a.solusi.solusi} (ditangani oleh ${nama})`, penulis, s)
    await aduan.catat(a.id, catatan === '' ? 'Admin menyetujui laporan pengerjaan dan meneruskannya ke klien' : `Admin menyetujui laporan pengerjaan dan meneruskannya ke klien: ${catatan}`, adminId, s)
    await aduan.perbarui(a.id, { status: 'Selesai', menunggu_verifikasi: 0 }, s)
    await aduan.catat(a.id, 'Status diubah: Selesai', adminId, s)
  })
}

/** PUT /tickets/:id/rating : Klien menilai aduan instansinya yang sudah Selesai. Boleh diganti selama masih Selesai. */
export async function beriNilai(ctx) {
  const isi = await ctx.isi()
  // Aduan instansi lain dijawab 404, sama dengan GET /tickets/:id
  return ubahAduan(
    ctx,
    async (a, s) => {
      if (a.status !== 'Selesai') throw new GalatHttp(409, 'BELUM_SELESAI', 'Aduan yang belum selesai belum bisa dinilai')
      validasi(isi, {
        score: [wajib('Nilai harus 1 sampai 5'), bulat('Nilai harus 1 sampai 5'), antara(1, 5, 'Nilai harus 1 sampai 5')],
      })
      await aduan.perbarui(a.id, { rating: Number(isi.score) }, s)
    },
    { batasInstansi: ctx.pengguna.instansiCode },
  )
}

/** PUT /tickets/:id/photos : foto dokumentasi perbaikan. Body { before?, after? }: alamat dari POST /uploads, null = hapus.
 *  Hanya kunci yang dikirim yang berubah. Hanya teknisi yang ditugaskan pada aduan itu. */
export async function foto(ctx) {
  const isi = await ctx.isi()
  return ubahAduan(ctx, async (a, s) => {
    if (!a.teknisi.some((t) => t.id === Number(ctx.pengguna.id))) {
      throw new GalatHttp(403, 'DILARANG', 'Aduan ini bukan tugasmu')
    }
    const kunci = Object.keys(KOLOM_FOTO).filter((k) => Object.hasOwn(isi, k))
    if (kunci.length === 0) throw new GalatHttp(422, 'VALIDASI', 'Tidak ada foto yang diubah')
    const ubahKolom = {}
    for (const k of kunci) ubahKolom[KOLOM_FOTO[k]] = periksaUrlFoto(isi[k])   // semua diperiksa dulu, baru disimpan
    await aduan.perbarui(a.id, ubahKolom, s)
  })
}
