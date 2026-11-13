import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { kenaliGambar } from '../../src/controllers/upload.js'
import { Router } from '../../src/core/router.js'
import { namaPelapor, potong, rawurlencode, slug } from '../../src/support/teks.js'
import { dariDb, iso, jamWib, keDb, tanggalSaja, tanggalValid, tanggalWib } from '../../src/support/waktu.js'
import { periksaUrlFoto } from '../../src/support/url-foto.js'
import { cocok, buatHash } from '../../src/auth/password.js'
import { GalatHttp } from '../../src/core/http-error.js'

describe('waktu', () => {
  it('teks database (UTC) menjadi ISO dengan milidetik', () => {
    assert.equal(iso('2026-09-30 02:10:00'), '2026-09-30T02:10:00.000Z')
  })
  it('keDb dan dariDb saling berbalik', () => {
    assert.equal(keDb(dariDb('2026-01-02 03:04:05')), '2026-01-02 03:04:05')
  })
  it('tanggalSaja', () => assert.equal(tanggalSaja('2026-09-12 08:00:00'), '2026-09-12'))
  it('tanggal dan jam Jakarta (UTC+7), termasuk pergantian hari', () => {
    assert.equal(tanggalWib('2026-11-06 03:30:43'), '6 Nov 2026')
    assert.equal(jamWib('2026-11-06 03:30:43'), '10.30')
    assert.equal(tanggalWib('2026-11-06 17:30:00'), '7 Nov 2026')   // lewat tengah malam WIB
    assert.equal(jamWib('2026-11-06 17:05:00'), '00.05')
    assert.equal(tanggalWib('2026-12-31 20:00:00'), '1 Jan 2027')
    assert.equal(tanggalWib('2026-05-01 00:00:00'), '1 Mei 2026')
    assert.equal(tanggalWib('2026-08-17 00:00:00'), '17 Agu 2026')
  })
  it('tanggalValid menolak tanggal yang tidak ada di kalender', () => {
    for (const ok of ['2026-12-01', '2028-02-29', '2026-01-31']) assert.ok(tanggalValid(ok), ok)
    for (const buruk of ['2026-02-30', '2027-02-29', '2026-13-01', '2026-00-10', '2026-04-31', '01-12-2026', '2026-1-1', 'abc', '']) {
      assert.ok(!tanggalValid(buruk), buruk)
    }
  })
})

describe('teks', () => {
  it('rawurlencode seperti PHP', () => {
    assert.equal(rawurlencode('Rafi Pratama'), 'Rafi%20Pratama')
    assert.equal(rawurlencode("a(b)c'd*e!"), 'a%28b%29c%27d%2Ae%21')
    assert.equal(rawurlencode('Ñandi'), '%C3%91andi')
  })
  it('slug untuk email teknisi', () => {
    assert.equal(slug('Budi Santoso'), 'budi.santoso')
    assert.equal(slug('Budi-Santoso'), 'budi.santoso')
    assert.equal(slug('Ñandi Çelik (Sr.)'), 'nandi.celik.sr')
    assert.equal(slug('  --  '), '')
  })
  it('potong menghitung karakter', () => {
    assert.equal(potong('😀😀😀abc', 0, 4), '😀😀😀a')
  })
  it('namaPelapor membuang keterangan dalam kurung', () => {
    assert.equal(namaPelapor('Bu Rina (SMK 2 Surabaya)'), 'Bu Rina')
    assert.equal(namaPelapor('Pak Yusuf'), 'Pak Yusuf')
  })
})

describe('kenaliGambar (dari isi berkas, bukan nama)', () => {
  const png = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 13]), Buffer.from('IHDR'), Buffer.alloc(8)])
  it('mengenali JPEG, PNG, GIF, WEBP', () => {
    assert.equal(kenaliGambar(Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0]))?.ekstensi, 'jpg')
    assert.equal(kenaliGambar(png)?.ekstensi, 'png')
    assert.equal(kenaliGambar(Buffer.from('GIF89a\x01\x00\x01\x00\x00\x00'))?.ekstensi, 'gif')
    assert.equal(kenaliGambar(Buffer.from('RIFF\x00\x00\x00\x00WEBPVP8 '))?.ekstensi, 'webp')
  })
  it('menolak yang bukan gambar, termasuk SVG dan HTML', () => {
    for (const isi of ['ini teks biasa', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<html></html>', '', 'GIF']) {
      assert.equal(kenaliGambar(Buffer.from(isi)), null, isi)
    }
  })
})

describe('periksaUrlFoto', () => {
  const nama = `${'a1'.repeat(16)}.png`
  it('menerima alamat dari /uploads dan null', () => {
    assert.equal(periksaUrlFoto(null), null)
    assert.equal(periksaUrlFoto(`http://localhost:3000/uploads/${nama}`), `http://localhost:3000/uploads/${nama}`)
    assert.equal(periksaUrlFoto(`/uploads/${nama}`), `/uploads/${nama}`)
    assert.equal(periksaUrlFoto(`https://situs.id/disty/uploads/${nama}`), `https://situs.id/disty/uploads/${nama}`)
  })
  it('menolak alamat asing, skrip, dan nama yang bukan 32 hex', () => {
    for (const buruk of ['http://evil.com/a.png', 'javascript:alert(1)', `//evil.com/uploads/${nama}`, '/uploads/abc.png', `/uploads/${nama}.php`,
      `/uploads/${'A1'.repeat(16)}.png`, 5, '', undefined, {}]) {
      assert.throws(() => periksaUrlFoto(buruk), (e) => e instanceof GalatHttp && e.status === 422, String(buruk))
    }
  })
})

describe('router', () => {
  const r = new Router()
  const h = () => {}
  r.tambah('GET', '/tickets', [], h)
  r.tambah('GET', '/tickets/:id', [], h)
  r.tambah('PATCH', '/tickets/:id', [], h)
  r.tambah('POST', '/tickets/:id/logs', [], h)
  it('mencocokkan dan memecah parameter (termasuk %20)', () => {
    assert.deepEqual(r.cocokkan('GET', '/tickets/HD-001').params, { id: 'HD-001' })
    assert.deepEqual(r.cocokkan('GET', '/tickets/a%20b').params, { id: 'a b' })
    assert.deepEqual(r.cocokkan('POST', '/tickets/HD-001/logs').params, { id: 'HD-001' })
  })
  it('alamat tidak ada -> null', () => {
    assert.equal(r.cocokkan('GET', '/xyz'), null)
    assert.equal(r.cocokkan('GET', '/tickets/a/b/c'), null)
  })
  it('alamat ada tapi metode salah -> daftar metode yang boleh', () => {
    assert.deepEqual(r.cocokkan('DELETE', '/tickets/HD-001').diperbolehkan, ['GET', 'PATCH'])
  })
  it('persen rusak -> 400', () => {
    assert.throws(() => r.cocokkan('GET', '/tickets/%E0%A4%A'), (e) => e.status === 400)
  })
})

describe('password (kompatibel dengan Laravel)', () => {
  it('hash buatan Node berawalan $2y$ dan bisa diverifikasi', async () => {
    process.env.BCRYPT_ROUNDS = '4'
    const hash = await buatHash('rahasia123')
    assert.match(hash, /^\$2y\$/)
    assert.equal(await cocok('rahasia123', hash), true)
    assert.equal(await cocok('salah', hash), false)
  })
  it('hash $2y$ dari PHP bisa diverifikasi (contoh baku password_hash)', async () => {
    // Hash di dokumentasi Laravel/PHP untuk kata sandi "password"
    assert.equal(await cocok('password', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), true)
  })
  it('hash rusak tidak melempar galat, hanya false', async () => {
    assert.equal(await cocok('x', 'bukan-hash'), false)
  })
})
