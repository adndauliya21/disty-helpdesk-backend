// Tes integrasi: server Node.js sungguhan + database MySQL tes (disty_helpdesk_test, dibuat ulang dari database/disty_helpdesk.sql).
// Dilewati otomatis bila MySQL tidak bisa dihubungi. Atur DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD bila perlu.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { after, before, describe, it } from 'node:test'
import mysql from 'mysql2/promise'
import { AKUN, nyalakanServer } from '../helper-db.js'

let s
let alasanLewati = false

async function bisaTerhubung() {
  try {
    const k = await mysql.createConnection({
      host: process.env.DB_HOST ?? '127.0.0.1', port: Number(process.env.DB_PORT ?? 3306),
      user: process.env.DB_USERNAME ?? 'root', password: process.env.DB_PASSWORD ?? '', connectTimeout: 2000,
    })
    await k.end()
    return true
  } catch {
    return false
  }
}

before(async () => {
  if (!(await bisaTerhubung())) {
    alasanLewati = 'MySQL tidak bisa dihubungi (atur DB_HOST, DB_USERNAME, DB_PASSWORD)'
    return
  }
  s = await nyalakanServer()
  for (const [nama, email] of Object.entries(AKUN)) await s.masuk(nama, email)
})
after(async () => { if (s) await s.tutup() })

const tes = (nama, fn) => it(nama, (t) => (alasanLewati ? t.skip(alasanLewati) : fn(t)))
const api = (...a) => s.api(...a)
const ok = (r, status) => assert.equal(r.status, status, `status ${r.status}: ${r.teks.slice(0, 300)}`)
const galat = (r, status, kode) => {
  assert.equal(r.status, status, `status ${r.status}: ${r.teks.slice(0, 300)}`)
  assert.equal(r.json.error.code, kode)
}

describe('dasar dan autentikasi', () => {
  tes('ping terbuka tanpa token', async () => {
    const r = await api('GET', '/ping')
    ok(r, 200)
    assert.equal(r.json.data.status, 'ok')
  })

  tes('alamat tidak ada -> 404 JSON, metode salah -> 405', async () => {
    galat(await api('GET', '/xyz'), 404, 'TIDAK_DITEMUKAN')
    const r = await api('DELETE', '/tickets')
    galat(r, 405, 'METODE_TIDAK_DIPERBOLEHKAN')
  })

  tes('CORS: preflight dijawab 204 dengan header yang benar', async () => {
    const r = await api('OPTIONS', '/tickets', { headers: { Origin: 'http://localhost:5500', 'Access-Control-Request-Method': 'GET' } })
    ok(r, 204)
    assert.equal(r.headers.get('access-control-allow-origin'), '*')
    assert.match(r.headers.get('access-control-allow-headers'), /Authorization/)
  })

  tes('login: bentuk balasan sesuai kontrak', async () => {
    const r = await api('POST', '/auth/login', { body: { email: AKUN.rina, password: 'rahasia123' } })
    ok(r, 200)
    assert.deepEqual(Object.keys(r.json.data), ['token', 'tokenType', 'expiresIn', 'user'])
    assert.deepEqual(r.json.data.user, { role: 'klien', name: 'Bu Rina', email: AKUN.rina, instansiCode: 'SMKN2-SBY' })
    assert.equal(r.json.data.tokenType, 'Bearer')
  })

  tes('login gagal memakai satu pesan yang sama untuk email salah dan sandi salah', async () => {
    const a = await api('POST', '/auth/login', { body: { email: AKUN.admin, password: 'salah' } })
    const b = await api('POST', '/auth/login', { body: { email: 'hantu@perusahaan.id', password: 'rahasia123' } })
    galat(a, 401, 'LOGIN_GAGAL')
    galat(b, 401, 'LOGIN_GAGAL')
    assert.equal(a.json.error.message, b.json.error.message)
  })

  tes('401 untuk token hilang, rusak, dan skema salah', async () => {
    galat(await api('GET', '/auth/me'), 401, 'TIDAK_TERAUTENTIKASI')
    galat(await api('GET', '/auth/me', { headers: { Authorization: 'Basic abc' } }), 401, 'TIDAK_TERAUTENTIKASI')
    galat(await api('GET', '/auth/me', { headers: { Authorization: 'Bearer sampah' } }), 401, 'TOKEN_TIDAK_VALID')
  })

  tes('JSON rusak -> 400, bukan objek -> 400, terlalu besar -> 413', async () => {
    galat(await api('POST', '/auth/login', { raw: '{bad' }), 400, 'JSON_RUSAK')
    galat(await api('POST', '/auth/login', { raw: '[1]' }), 400, 'JSON_RUSAK')
    galat(await api('POST', '/auth/login', { raw: JSON.stringify({ password: 'x'.repeat(1_100_000) }) }), 413, 'BODY_TERLALU_BESAR')
  })

  tes('akun nonaktif tidak bisa login', async () => {
    const t = await api('POST', '/technicians', { akun: 'admin', body: { name: 'Teknisi Baru', specialty: 'Umum' } })
    ok(t, 201)
    galat(await api('POST', '/auth/login', { body: { email: 'teknisi.baru@perusahaan.id', password: 'rahasia123' } }), 401, 'LOGIN_GAGAL')
  })
})

describe('aduan', () => {
  tes('admin melihat 12 aduan, terbaru di depan, kunci sesuai kontrak', async () => {
    const r = await api('GET', '/tickets', { akun: 'admin' })
    ok(r, 200)
    assert.equal(r.json.data.length, 12)
    assert.equal(r.json.data[0].id, 'HD-012')
    assert.deepEqual(Object.keys(r.json.data[0]), [
      'id', 'title', 'category', 'module', 'description', 'status', 'priority', 'channel', 'awaitingVerification', 'technicians', 'attachment', 'photos',
      'instansiCode', 'reporter', 'rating', 'solution', 'logs', 'createdAt',
    ])
  })

  tes('klien hanya melihat aduan instansinya, aduan instansi lain dijawab 404', async () => {
    const r = await api('GET', '/tickets', { akun: 'rina' })
    assert.deepEqual(r.json.data.map((a) => a.id), ['HD-001'])
    galat(await api('GET', '/tickets/HD-012', { akun: 'rina' }), 404, 'TIDAK_DITEMUKAN')
  })

  tes('saringan status dan prioritas, nilai salah -> 422', async () => {
    assert.equal((await api('GET', '/tickets?status=Selesai', { akun: 'admin' })).json.data.length, 8)
    assert.equal((await api('GET', '/tickets?priority=Tinggi', { akun: 'admin' })).json.data.length, 4)
    galat(await api('GET', '/tickets?status=Salah', { akun: 'admin' }), 422, 'VALIDASI')
  })

  tes('alur penuh: buat -> tugaskan -> catat -> solusi -> nilai', async () => {
    const baru = await api('POST', '/tickets', {
      akun: 'citra', body: { title: 'Laporan error', description: 'Muncul 500.', category: 'Gangguan / Error', module: 'Laporan' },
    })
    ok(baru, 201)
    const id = baru.json.data.id
    assert.equal(baru.headers.get('location'), `/api/v1/tickets/${id}`)
    assert.equal(baru.json.data.status, 'Baru')
    assert.equal(baru.json.data.instansiCode, 'SMKN1-MLG')
    assert.equal(baru.json.data.logs[0].message, 'Aduan dikirim')

    // Admin menetapkan prioritas lalu menugaskan: teknisi pertama membuat status Diproses
    ok(await api('PATCH', `/tickets/${id}`, { akun: 'admin', body: { priority: 'Urgent' } }), 200)
    const tugas = await api('POST', `/tickets/${id}/assignments`, { akun: 'admin', body: { technician: 'Rafi Pratama' } })
    ok(tugas, 200)
    assert.equal(tugas.json.data.status, 'Diproses')
    assert.deepEqual(tugas.json.data.technicians, ['Rafi Pratama'])
    galat(await api('POST', `/tickets/${id}/assignments`, { akun: 'admin', body: { technician: 'rafi pratama' } }), 409, 'SUDAH_DITUGASKAN')

    ok(await api('POST', `/tickets/${id}/logs`, { akun: 'rafi', body: { message: 'Sudah dicek' } }), 201)

    // Urutan wajib: Diproses -> Monitoring -> Selesai. Melompat langsung ke Selesai ditolak, lewat PATCH maupun lewat solusi.
    galat(await api('PATCH', `/tickets/${id}`, { akun: 'rafi', body: { status: 'Selesai', note: 'Langsung selesai' } }), 409, 'URUTAN_STATUS_TIDAK_VALID')
    galat(await api('PUT', `/tickets/${id}/solution`, { akun: 'rafi', body: { part: 'Laporan', summary: 'Query diperbaiki', handledBy: ['Rafi Pratama'] } }), 409, 'URUTAN_STATUS_TIDAK_VALID')
    assert.equal((await api('GET', `/tickets/${id}`, { akun: 'admin' })).json.data.status, 'Diproses')

    // Catatan tahapan wajib diisi setiap pindah status
    galat(await api('PATCH', `/tickets/${id}`, { akun: 'rafi', body: { status: 'Monitoring' } }), 422, 'VALIDASI')
    galat(await api('PATCH', `/tickets/${id}`, { akun: 'rafi', body: { status: 'Monitoring', note: '   ' } }), 422, 'VALIDASI')
    const pantau = await api('PATCH', `/tickets/${id}`, { akun: 'rafi', body: { status: 'Monitoring', note: '  Query sudah diperbaiki, dipantau  ' } })
    ok(pantau, 200)
    assert.equal(pantau.json.data.status, 'Monitoring')
    assert.equal(pantau.json.data.logs.at(-2).message, 'Query sudah diperbaiki, dipantau')
    assert.equal(pantau.json.data.logs.at(-1).message, 'Status diubah: Monitoring')

    // Dari Monitoring tidak boleh mundur, dan Selesai tetap butuh solusi
    galat(await api('PATCH', `/tickets/${id}`, { akun: 'rafi', body: { status: 'Diproses', note: 'Mundur' } }), 409, 'URUTAN_STATUS_TIDAK_VALID')
    galat(await api('PATCH', `/tickets/${id}`, { akun: 'rafi', body: { status: 'Selesai', note: 'Beres' } }), 409, 'SOLUSI_BELUM_ADA')

    const solusi = await api('PUT', `/tickets/${id}/solution`, {
      akun: 'rafi', body: { part: 'Laporan', summary: 'Query diperbaiki', handledBy: ['Rafi Pratama', 'Dina'] },
    })
    ok(solusi, 200)
    assert.equal(solusi.json.data.status, 'Selesai')
    assert.deepEqual(solusi.json.data.solution.handledBy, ['Rafi Pratama', 'Dina'])
    assert.equal(solusi.json.data.logs.at(-1).message, 'Status diubah: Selesai')
    galat(await api('PUT', `/tickets/${id}/solution`, { akun: 'rafi', body: { part: 'x', summary: 'y', handledBy: ['Dina'] } }), 409, 'SUDAH_SELESAI')

    ok(await api('PUT', `/tickets/${id}/rating`, { akun: 'citra', body: { score: 5 } }), 200)
    assert.equal((await api('GET', `/tickets/${id}`, { akun: 'admin' })).json.data.rating, 5)
    galat(await api('PUT', `/tickets/${id}/rating`, { akun: 'citra', body: { score: 6 } }), 422, 'VALIDASI')
  })

  tes('aturan peran: klien tidak boleh mengubah, admin tidak boleh membuat', async () => {
    galat(await api('PATCH', '/tickets/HD-010', { akun: 'rina', body: { status: 'Selesai' } }), 403, 'DILARANG')
    galat(await api('POST', '/tickets', { akun: 'admin', body: {} }), 403, 'DILARANG')
    galat(await api('PATCH', '/tickets/HD-010', { akun: 'rafi', body: { priority: 'Rendah' } }), 403, 'DILARANG')
    galat(await api('PATCH', '/tickets/HD-010', { akun: 'admin', body: { status: 'Monitoring' } }), 403, 'DILARANG')
  })

  tes('validasi pembuatan aduan memakai pesan yang tampil di frontend', async () => {
    const dasar = { title: 'T', description: 'D', category: 'Gangguan / Error' }
    let r = await api('POST', '/tickets', { akun: 'rina', body: { ...dasar, title: ' ' } })
    galat(r, 422, 'VALIDASI')
    assert.equal(r.json.error.message, 'Judul aduan wajib diisi')
    r = await api('POST', '/tickets', { akun: 'rina', body: { ...dasar, category: 'Lain' } })
    assert.equal(r.json.error.message, 'Jenis bantuan tidak dikenal')
    r = await api('POST', '/tickets', { akun: 'rina', body: { ...dasar, title: 'a'.repeat(101) } })
    assert.equal(r.json.error.message, 'Judul aduan maksimal 100 karakter')
    assert.ok('title' in r.json.error.details)
  })

  tes('foto dokumentasi: hanya teknisi yang ditugaskan dan hanya alamat dari /uploads', async () => {
    const form = new FormData()
    form.append('file', new Blob([readFileSync(new URL('./gambar-kecil.png', import.meta.url))], { type: 'image/png' }), 'a.png')
    const up = await api('POST', '/uploads', { akun: 'bayu', form })
    ok(up, 201)
    const url = up.json.data.url
    ok(await api('PUT', '/tickets/HD-012/photos', { akun: 'bayu', body: { before: url } }), 200)
    galat(await api('PUT', '/tickets/HD-012/photos', { akun: 'rafi', body: { before: url } }), 403, 'DILARANG')
    galat(await api('PUT', '/tickets/HD-012/photos', { akun: 'bayu', body: { after: 'http://evil.com/x.png' } }), 422, 'VALIDASI')
    ok(await api('PUT', '/tickets/HD-012/photos', { akun: 'bayu', body: { before: null } }), 200)
  })
})

describe('unggah foto dan penyajian berkas', () => {
  const buatForm = (isi, nama, tipe) => {
    const f = new FormData()
    f.append('file', new Blob([isi], { type: tipe }), nama)
    return f
  }

  tes('gambar sah disimpan dan bisa dibuka lewat alamat yang dikembalikan', async () => {
    const png = readFileSync(new URL('./gambar-kecil.png', import.meta.url))
    const r = await api('POST', '/uploads', { akun: 'rina', form: buatForm(png, 'foto.png', 'image/png') })
    ok(r, 201)
    assert.match(r.json.data.url, /\/uploads\/[a-f0-9]{32}\.png$/)
    assert.equal(r.json.data.type, 'image/png')
    assert.equal(r.json.data.size, png.length)
    const buka = await fetch(r.json.data.url)
    assert.equal(buka.status, 200)
    assert.equal(buka.headers.get('content-type'), 'image/png')
    assert.deepEqual(Buffer.from(await buka.arrayBuffer()), png)
  })

  tes('jenis ditentukan dari isi berkas: teks bernama .png ditolak', async () => {
    const r = await api('POST', '/uploads', { akun: 'rina', form: buatForm('ini bukan gambar', 'palsu.png', 'image/png') })
    galat(r, 422, 'VALIDASI')
    assert.equal(r.json.error.message, 'Foto harus berformat JPG, PNG, WEBP, atau GIF')
  })

  tes('SVG ditolak (bisa memuat skrip)', async () => {
    const r = await api('POST', '/uploads', { akun: 'rina', form: buatForm('<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'x.svg', 'image/svg+xml') })
    galat(r, 422, 'VALIDASI')
  })

  tes('lebih dari 5 MB ditolak, tanpa berkas dan tanpa token juga', async () => {
    const png = readFileSync(new URL('./gambar-kecil.png', import.meta.url))
    const besar = Buffer.concat([png, Buffer.alloc(5 * 1024 * 1024)])
    const r = await api('POST', '/uploads', { akun: 'rina', form: buatForm(besar, 'besar.png', 'image/png') })
    galat(r, 422, 'VALIDASI')
    assert.equal(r.json.error.message, 'Ukuran foto maksimal 5 MB')
    assert.equal((await api('POST', '/uploads', { akun: 'rina', body: {} })).json.error.message, 'Pilih foto dulu')
    galat(await api('POST', '/uploads', { form: buatForm(png, 'a.png', 'image/png') }), 401, 'TIDAK_TERAUTENTIKASI')
  })

  tes('frontend disajikan, berkas tersembunyi dan skrip PHP tidak', async () => {
    for (const [jalur, status] of [['/', 200], ['/index.html', 200], ['/login.html', 200], ['/.env', 404], ['/index.php', 404], ['/%2e%2e/.env', 404], ['/js/../.env', 404]]) {
      const r = await fetch(s.url + jalur, { redirect: 'manual' })
      assert.equal(r.status, status, jalur)
    }
  })
})

describe('konsultasi dan kuota', () => {
  const k = { topic: 'Latihan', note: '', date: '2027-03-02', time: '10.00' }

  tes('kuota bawaan 2 per jam: pengajuan ketiga ditolak, penolakan membebaskan kuota', async () => {
    const a = await api('POST', '/consultations', { akun: 'rina', body: k })
    ok(a, 201)
    ok(await api('POST', '/consultations', { akun: 'yusuf', body: k }), 201)
    galat(await api('POST', '/consultations', { akun: 'citra', body: k }), 409, 'KUOTA_PENUH')
    ok(await api('PATCH', `/consultations/${a.json.data.id}`, { akun: 'admin', body: { status: 'Ditolak' } }), 200)
    ok(await api('POST', '/consultations', { akun: 'citra', body: k }), 201)
  })

  tes('validasi tanggal dan jam', async () => {
    galat(await api('POST', '/consultations', { akun: 'rina', body: { ...k, date: '2027-02-30' } }), 422, 'VALIDASI')
    galat(await api('POST', '/consultations', { akun: 'rina', body: { ...k, time: '08.00' } }), 409, 'KUOTA_PENUH')
  })

  tes('Terjadwal butuh teknisi, lalu admin bisa membalas', async () => {
    galat(await api('PATCH', '/consultations/4', { akun: 'admin', body: { status: 'Terjadwal' } }), 409, 'TEKNISI_KOSONG')
    ok(await api('PUT', '/consultations/4/technician', { akun: 'admin', body: { technician: 'Dina' } }), 200)
    ok(await api('PATCH', '/consultations/4', { akun: 'admin', body: { status: 'Terjadwal' } }), 200)
    const r = await api('POST', '/consultations/4/messages', { akun: 'admin', body: { text: 'Baik, kami jadwalkan.' } })
    ok(r, 201)
    assert.equal(r.json.data.messages.at(-1).from, 'a')
    assert.equal(r.json.data.technician, 'Dina')
  })

  tes('id yang bukan angka atau tidak ada -> 404', async () => {
    galat(await api('PATCH', '/consultations/abc', { akun: 'admin', body: { status: 'Jadi' } }), 404, 'TIDAK_DITEMUKAN')
    galat(await api('PATCH', '/consultations/99999', { akun: 'admin', body: { status: 'Jadi' } }), 404, 'TIDAK_DITEMUKAN')
  })

  tes('kuota: admin mengganti seluruhnya, kuota khusus mengalahkan kuota bawaan', async () => {
    const defaults = { '09.00': 2, '10.00': 2, '11.00': 2, '13.00': 2, '14.00': 2, '15.00': 2 }
    const r = await api('PUT', '/consultation-quota', { akun: 'admin', body: { defaults, overrides: { '2027-04-01|09.00': 0 } } })
    ok(r, 200)
    assert.deepEqual(r.json.data.overrides, { '2027-04-01|09.00': 0 })
    galat(await api('POST', '/consultations', { akun: 'rina', body: { ...k, date: '2027-04-01', time: '09.00' } }), 409, 'KUOTA_PENUH')
    galat(await api('PUT', '/consultation-quota', { akun: 'admin', body: { defaults: { ...defaults, '09.00': 21 } } }), 422, 'VALIDASI')
    galat(await api('PUT', '/consultation-quota', { akun: 'admin', body: { defaults, overrides: { salah: 1 } } }), 422, 'VALIDASI')
    galat(await api('PUT', '/consultation-quota', { akun: 'rina', body: { defaults } }), 403, 'DILARANG')
  })
})

describe('survei, referensi, dan teknisi', () => {
  tes('survei: kirim, baca, kirim ulang menimpa', async () => {
    assert.equal((await api('GET', '/surveys/me', { akun: 'hesti' })).json.data, null)
    ok(await api('PUT', '/surveys/me', { akun: 'hesti', body: { overall: 4, aspects: [5, 4, 3], resolved: 'Ya, sudah', comment: ' Mantap ' } }), 200)
    const r = await api('PUT', '/surveys/me', { akun: 'hesti', body: { overall: 2 } })
    assert.equal(r.json.data.overall, 2)
    assert.deepEqual(r.json.data.aspects, [0, 0, 0])
    assert.equal(r.json.data.resolved, 'Belum')
    galat(await api('PUT', '/surveys/me', { akun: 'hesti', body: { overall: '4' } }), 422, 'VALIDASI')
    galat(await api('PUT', '/surveys/me', { akun: 'admin', body: { overall: 4 } }), 403, 'DILARANG')
  })

  tes('data referensi', async () => {
    const kb = (await api('GET', '/knowledge-base', { akun: 'rina' })).json.data
    assert.deepEqual(Object.keys(kb), ['Database', 'Login / Akun', 'Laporan', 'Pembayaran', 'Tampilan', 'Lainnya'])
    assert.deepEqual((await api('GET', '/solutions', { akun: 'rina' })).json.data, [])
    const inst = (await api('GET', '/institutions', { akun: 'rina' })).json.data
    assert.equal(Object.keys(inst).length, 20)
    assert.equal(inst['SMKN2-SBY'].dom, 'smkn2.sch.id')
    assert.deepEqual(Object.keys(inst['SMKN2-SBY'].p[0]), ['n', 'j', 'e', 'tel', 'g', 'login', 'last', 'h', 'k', 'v', 'act'])
  })

  tes('teknisi: CRUD, nama di alamat, dan perlindungan data yang dipakai', async () => {
    assert.equal((await api('GET', '/technicians/Rafi%20Pratama', { akun: 'rina' })).json.data.name, 'Rafi Pratama')
    galat(await api('GET', '/technicians/Hantu', { akun: 'rina' }), 404, 'TIDAK_DITEMUKAN')
    const baru = await api('POST', '/technicians', { akun: 'admin', body: { name: 'Budi Santoso', specialty: 'Jaringan' } })
    ok(baru, 201)
    assert.equal(baru.headers.get('location'), '/api/v1/technicians/Budi%20Santoso')
    galat(await api('POST', '/technicians', { akun: 'admin', body: { name: 'budi santoso', specialty: 'Jaringan' } }), 409, 'SUDAH_ADA')
    // Nama hanya huruf (tanpa angka/simbol) dan spesialisasi wajib: ditolak 422 dan tidak tersimpan.
    for (const nama of ['@@123', 'Budi123', '123', 'Budi!', '-Budi']) {
      const r = await api('POST', '/technicians', { akun: 'admin', body: { name: nama, specialty: 'Jaringan' } })
      galat(r, 422, 'VALIDASI')
      assert.match(r.json.error.message, /^Format nama tidak valid/)
    }
    for (const body of [{ name: 'Tanpa Spesialisasi' }, { name: 'Tanpa Spesialisasi', specialty: '' }, { name: 'Tanpa Spesialisasi', specialty: '   ' }]) {
      const r = await api('POST', '/technicians', { akun: 'admin', body })
      galat(r, 422, 'VALIDASI')
      assert.equal(r.json.error.message, 'Spesialisasi wajib diisi')
    }
    galat(await api('GET', '/technicians/Tanpa%20Spesialisasi', { akun: 'admin' }), 404, 'TIDAK_DITEMUKAN')
    const u = await api('PUT', '/technicians/Budi%20Santoso', { akun: 'admin', body: { specialty: 'Server' } })
    assert.equal(u.json.data.specialty, 'Server')
    galat(await api('PUT', '/technicians/Budi%20Santoso', { akun: 'admin', body: { name: 'Lain', specialty: 'x' } }), 422, 'VALIDASI')
    galat(await api('DELETE', '/technicians/Rafi%20Pratama', { akun: 'admin' }), 409, 'MASIH_DIPAKAI')
    ok(await api('DELETE', '/technicians/Budi%20Santoso', { akun: 'admin' }), 204)
    galat(await api('POST', '/technicians', { akun: 'rina', body: { name: 'X' } }), 403, 'DILARANG')
  })

  tes('profil dan kata sandi', async () => {
    galat(await api('PUT', '/me', { akun: 'yusuf', body: { name: 'Pak Yusuf', email: 'yusuf@gmail.com' } }), 422, 'INSTANSI_TIDAK_DIKENAL')
    galat(await api('PUT', '/me', { akun: 'yusuf', body: { name: 'Pak Yusuf', email: AKUN.rina } }), 409, 'EMAIL_DIPAKAI')
    const r = await api('PUT', '/me', { akun: 'yusuf', body: { name: 'Pak Yusuf B', email: 'Yusuf@SMAN1SDA.sch.id' } })
    assert.equal(r.json.data.instansiCode, 'SMAN1-SDA')   // pindah instansi mengikuti domain email baru
    galat(await api('PUT', '/me/password', { akun: 'yusuf', body: { currentPassword: 'salah', newPassword: 'baru12345' } }), 403, 'SANDI_SALAH')
    galat(await api('PUT', '/me/password', { akun: 'yusuf', body: { currentPassword: 'rahasia123', newPassword: 'abc' } }), 422, 'VALIDASI')
    ok(await api('PUT', '/me/password', { akun: 'yusuf', body: { currentPassword: 'rahasia123', newPassword: 'baru12345' } }), 204)
    ok(await api('POST', '/auth/login', { body: { email: 'yusuf@sman1sda.sch.id', password: 'baru12345' } }), 200)
    galat(await api('POST', '/auth/login', { body: { email: 'yusuf@sman1sda.sch.id', password: 'rahasia123' } }), 401, 'LOGIN_GAGAL')
  })
})

// Aduan lewat telepon: klien menelepon teknisi langsung (tanpa admin), teknisi mencatat aduannya.
// Saat selesai, alurnya Teknisi -> Admin -> Klien. Padanan tests/Feature/AduanTeleponTest.php di Laravel.
describe('aduan telepon', () => {
  const isian = (ubah = {}) => ({
    reporterEmail: 'rina@smkn2.sch.id',
    title: 'Printer lab mati total',
    category: 'Gangguan / Error',
    module: 'Jaringan',
    description: 'Klien menelepon: printer lab tidak menyala sejak pagi.',
    priority: 'Tinggi',
    ...ubah,
  })
  const catat = async (akun = 'rafi', ubah = {}) => {
    const r = await api('POST', '/tickets/phone', { akun, body: isian(ubah) })
    ok(r, 201)
    return r.json.data
  }
  const kirimLaporan = async (kode, akun = 'rafi') => {
    ok(await api('PATCH', `/tickets/${kode}`, { akun, body: { status: 'Monitoring', note: 'Perbaikan selesai, dipantau dulu.' } }), 200)
    ok(await api('PUT', `/tickets/${kode}/solution`, { akun, body: { part: 'Jaringan', summary: 'Kabel daya diganti.', handledBy: ['Rafi Pratama'] } }), 200)
  }

  tes('teknisi mencatat aduan telepon: langsung Diproses, teknisi utama, tanpa admin', async () => {
    const a = await catat()
    assert.equal(a.status, 'Diproses')
    assert.equal(a.channel, 'telepon')
    assert.equal(a.awaitingVerification, false)
    assert.deepEqual(a.technicians, ['Rafi Pratama'])
    assert.equal(a.instansiCode, 'SMKN2-SBY')
    assert.match(a.logs[0].message, /lewat telepon/)
    assert.equal(a.logs[0].actor, 'Rafi Pratama')
  })

  tes('aduan telepon muncul di Aduan Saya klien dengan status Diproses; instansi lain tidak melihatnya', async () => {
    const kode = (await catat()).id
    const daftar = await api('GET', '/tickets', { akun: 'rina' })
    const ini = daftar.json.data.find((t) => t.id === kode)
    assert.equal(ini.status, 'Diproses')
    assert.equal(ini.channel, 'telepon')
    ok(await api('GET', `/tickets/${kode}`, { akun: 'rina' }), 200)
    galat(await api('GET', `/tickets/${kode}`, { akun: 'citra' }), 404, 'TIDAK_DITEMUKAN')
  })

  tes('hanya teknisi boleh mencatat; klien tidak dikenal, bukan klien, atau belum aktif ditolak', async () => {
    galat(await api('POST', '/tickets/phone', { akun: 'rina', body: isian() }), 403, 'DILARANG')
    galat(await api('POST', '/tickets/phone', { akun: 'admin', body: isian() }), 403, 'DILARANG')
    galat(await api('POST', '/tickets/phone', { body: isian() }), 401, 'TIDAK_TERAUTENTIKASI')
    const kosong = await api('POST', '/tickets/phone', { akun: 'rafi', body: isian({ reporterEmail: '' }) })
    galat(kosong, 422, 'VALIDASI')
    assert.equal(kosong.json.error.message, 'Pilih klien yang menelepon')
    galat(await api('POST', '/tickets/phone', { akun: 'rafi', body: isian({ title: '' }) }), 422, 'VALIDASI')
    galat(await api('POST', '/tickets/phone', { akun: 'rafi', body: isian({ reporterEmail: 'tidakada@smkn2.sch.id' }) }), 404, 'TIDAK_DITEMUKAN')
    galat(await api('POST', '/tickets/phone', { akun: 'rafi', body: isian({ reporterEmail: AKUN.admin }) }), 404, 'TIDAK_DITEMUKAN')
    await s.pool.query("UPDATE pengguna SET status = 'nonaktif' WHERE email = 'rina@smkn2.sch.id'")
    galat(await api('POST', '/tickets/phone', { akun: 'rafi', body: isian() }), 409, 'AKUN_BELUM_AKTIF')
    await s.pool.query("UPDATE pengguna SET status = 'aktif' WHERE email = 'rina@smkn2.sch.id'")
  })

  tes('laporan selesai dikirim ke admin dulu: klien belum melihat solusi dan tidak bisa menilai', async () => {
    const kode = (await catat()).id
    await kirimLaporan(kode)
    for (const akun of ['rafi', 'admin']) {
      const d = (await api('GET', `/tickets/${kode}`, { akun })).json.data
      assert.equal(d.status, 'Monitoring')
      assert.equal(d.awaitingVerification, true)
      assert.equal(d.solution.summary, 'Kabel daya diganti.')
    }
    const d = (await api('GET', `/tickets/${kode}`, { akun: 'rina' })).json.data
    assert.equal(d.status, 'Monitoring')
    assert.equal(d.solution, null)
    assert.ok(!JSON.stringify(d.logs).includes('Kabel daya diganti'))
    galat(await api('PUT', `/tickets/${kode}/rating`, { akun: 'rina', body: { score: 5 } }), 409, 'BELUM_SELESAI')
    // teknisi tidak bisa melangkahi admin
    galat(await api('PATCH', `/tickets/${kode}`, { akun: 'rafi', body: { status: 'Selesai', note: 'x' } }), 409, 'PERLU_VERIFIKASI_ADMIN')
    galat(await api('PUT', `/tickets/${kode}/solution`, { akun: 'rafi', body: { part: 'Jaringan', summary: 'Lagi.', handledBy: ['Rafi Pratama'] } }), 409, 'MENUNGGU_VERIFIKASI')
  })

  tes('admin menyetujui: aduan Selesai dan klien menerima solusi', async () => {
    const kode = (await catat()).id
    await kirimLaporan(kode)
    const d = (await api('PUT', `/tickets/${kode}/verification`, { akun: 'admin', body: { approve: true } })).json.data
    assert.equal(d.status, 'Selesai')
    assert.equal(d.awaitingVerification, false)
    const klien = (await api('GET', `/tickets/${kode}`, { akun: 'rina' })).json.data
    assert.equal(klien.status, 'Selesai')
    assert.equal(klien.solution.summary, 'Kabel daya diganti.')
    assert.equal(klien.logs.at(-1).actor, 'Admin')
    assert.match(klien.logs.at(-1).message, /Selesai/)
    const nilai = await api('PUT', `/tickets/${kode}/rating`, { akun: 'rina', body: { score: 5 } })
    ok(nilai, 200)
    assert.equal(nilai.json.data.rating, 5)
  })

  tes('admin mengembalikan ke teknisi (alasan wajib), teknisi mengirim ulang, lalu disetujui', async () => {
    const kode = (await catat()).id
    await kirimLaporan(kode)
    const tanpaAlasan = await api('PUT', `/tickets/${kode}/verification`, { akun: 'admin', body: { approve: false } })
    galat(tanpaAlasan, 422, 'VALIDASI')
    assert.equal(tanpaAlasan.json.error.message, 'Tulis alasan pengembalian untuk teknisi')
    const kembali = (await api('PUT', `/tickets/${kode}/verification`, { akun: 'admin', body: { approve: false, note: 'Tambahkan nomor seri printer.' } })).json.data
    assert.equal(kembali.status, 'Monitoring')
    assert.equal(kembali.awaitingVerification, false)
    ok(await api('PUT', `/tickets/${kode}/solution`, { akun: 'rafi', body: { part: 'Jaringan', summary: 'Kabel daya diganti, printer seri X1.', handledBy: ['Rafi Pratama'] } }), 200)
    ok(await api('PUT', `/tickets/${kode}/verification`, { akun: 'admin', body: { approve: true } }), 200)
    assert.equal((await api('GET', `/tickets/${kode}`, { akun: 'rina' })).json.data.solution.summary, 'Kabel daya diganti, printer seri X1.')
  })

  tes('verifikasi hanya admin dan hanya saat menunggu; aduan web tidak berubah', async () => {
    const kode = (await catat()).id
    galat(await api('PUT', `/tickets/${kode}/verification`, { akun: 'rafi', body: { approve: true } }), 403, 'DILARANG')
    galat(await api('PUT', `/tickets/${kode}/verification`, { akun: 'rina', body: { approve: true } }), 403, 'DILARANG')
    galat(await api('PUT', `/tickets/${kode}/verification`, { akun: 'admin', body: { approve: true } }), 409, 'TIDAK_MENUNGGU_VERIFIKASI')
    galat(await api('PUT', `/tickets/${kode}/verification`, { akun: 'admin', body: { approve: 'ya' } }), 422, 'VALIDASI')
    const web = (await api('GET', '/tickets/HD-010', { akun: 'admin' })).json.data
    assert.equal(web.channel, 'web')
    assert.equal(web.awaitingVerification, false)
    galat(await api('PUT', '/tickets/HD-010/verification', { akun: 'admin', body: { approve: true } }), 409, 'TIDAK_MENUNGGU_VERIFIKASI')
  })

  tes('kontak teknisi terbuka tanpa login, hanya teknisi aktif dan hanya tiga kunci', async () => {
    const r = await api('GET', '/contacts/technicians')
    ok(r, 200)
    const rafi = r.json.data.find((t) => t.name === 'Rafi Pratama')
    assert.equal(rafi.phone, '081230000002')
    assert.equal(rafi.specialty, 'Database')
    assert.deepEqual(Object.keys(rafi).sort(), ['name', 'phone', 'specialty'])
    await s.pool.query("UPDATE pengguna SET status = 'nonaktif' WHERE email = 'dina@perusahaan.id'")
    assert.ok(!(await api('GET', '/contacts/technicians')).json.data.some((t) => t.name === 'Dina'))
    await s.pool.query("UPDATE pengguna SET status = 'aktif' WHERE email = 'dina@perusahaan.id'")
  })
})
