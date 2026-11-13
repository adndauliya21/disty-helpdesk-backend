import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { GalatHttp } from '../../src/core/http-error.js'
import {
  antara, bolehKosong, bulat, email, enumDariQuery, larik, maks, minimal, teks, validasi, wajib,
} from '../../src/core/validator.js'

const gagal = (isi, aturan) => {
  try { validasi(isi, aturan) } catch (e) { assert.ok(e instanceof GalatHttp); return e }
  assert.fail('seharusnya gagal')
}

describe('validasi()', () => {
  const aturan = {
    judul: [wajib('Judul wajib'), teks('Judul wajib'), maks(5, 'Judul maks 5')],
    catatan: [bolehKosong(), teks('Catatan teks'), maks(3, 'Catatan maks 3')],
  }

  it('lolos bila semua benar', () => {
    assert.doesNotThrow(() => validasi({ judul: 'abc' }, aturan))
  })

  it('422 VALIDASI dengan pesan pertama dan rincian per field', () => {
    const e = gagal({ judul: '', catatan: 'panjang sekali' }, aturan)
    assert.equal(e.status, 422)
    assert.equal(e.kode, 'VALIDASI')
    assert.equal(e.message, 'Judul wajib')
    assert.deepEqual(e.rincian, { judul: 'Judul wajib', catatan: 'Catatan maks 3' })
  })

  it('spasi di tepi dibuang sebelum diperiksa', () => {
    assert.doesNotThrow(() => validasi({ judul: '  abc  ' }, aturan))
    assert.equal(gagal({ judul: '     ' }, aturan).message, 'Judul wajib')
  })

  it('berhenti di pelanggaran pertama per field (bail)', () => {
    assert.equal(gagal({ judul: 5 }, aturan).rincian.judul, 'Judul wajib')   // bukan teks, tidak lanjut ke maks
  })

  it('maks menghitung karakter, bukan byte', () => {
    assert.doesNotThrow(() => validasi({ judul: 'éééée' }, aturan))
    assert.doesNotThrow(() => validasi({ judul: '😀😀😀😀😀' }, aturan))
    assert.throws(() => validasi({ judul: '😀😀😀😀😀😀' }, aturan), GalatHttp)
  })

  it('bolehKosong: tidak dikirim, null, dan teks kosong dilewati', () => {
    for (const isi of [{ judul: 'a' }, { judul: 'a', catatan: null }, { judul: 'a', catatan: '' }, { judul: 'a', catatan: '   ' }]) {
      assert.doesNotThrow(() => validasi(isi, aturan))
    }
  })

  it('bolehKosong tetap memeriksa bila ada isinya', () => {
    assert.equal(gagal({ judul: 'a', catatan: 5 }, aturan).rincian.catatan, 'Catatan teks')
  })

  it('wajib gagal untuk null, tidak ada, dan larik kosong, tapi lolos untuk 0 dan false', () => {
    const a = { x: [wajib('x wajib')] }
    for (const isi of [{}, { x: null }, { x: '' }, { x: [] }]) assert.throws(() => validasi(isi, a), GalatHttp)
    for (const isi of [{ x: 0 }, { x: false }, { x: 'a' }]) assert.doesNotThrow(() => validasi(isi, a))
  })

  it('aturan berbintang memeriksa tiap elemen larik', () => {
    const a = {
      tim: [wajib('Pilih tim'), larik('Pilih tim'), minimal(1, 'Pilih tim')],
      'tim.*': [wajib('Nama kosong'), teks('Nama teks'), maks(3, 'Nama maks 3')],
    }
    assert.doesNotThrow(() => validasi({ tim: ['abc', 'de'] }, a))
    assert.equal(gagal({ tim: ['abc', ''] }, a).rincian['tim.1'], 'Nama kosong')
    assert.equal(gagal({ tim: [5] }, a).rincian['tim.0'], 'Nama teks')
    assert.equal(gagal({ tim: ['abcd'] }, a).message, 'Nama maks 3')
    assert.equal(gagal({ tim: [] }, a).message, 'Pilih tim')
    assert.equal(gagal({ tim: 'abc' }, a).message, 'Pilih tim')   // bukan larik: elemen tidak diperiksa
  })

  it('email', () => {
    const a = { e: [wajib('e'), teks('e'), email('e')] }
    for (const ok of ['a@b.id', 'rina@smkn2.sch.id', 'a.b+c@x.co.id']) assert.doesNotThrow(() => validasi({ e: ok }, a), ok)
    for (const buruk of ['a', 'a@', '@b.id', 'a b@c.id', 'a@b@c.id', 'a@b..id', 'a@.id']) assert.throws(() => validasi({ e: buruk }, a), GalatHttp, buruk)
  })

  it('bulat dan antara', () => {
    const a = { n: [wajib('n'), bulat('n'), antara(1, 5, 'n')] }
    for (const ok of [1, 5, '3', ' 4 ']) assert.doesNotThrow(() => validasi({ n: ok }, a), String(ok))
    for (const buruk of [0, 6, 2.5, 'x', null, true, [], {}, '2.5']) assert.throws(() => validasi({ n: buruk }, a), GalatHttp, JSON.stringify(buruk))
  })
})

describe('enumDariQuery()', () => {
  const daftar = ['Baru', 'Selesai']
  it('kosong atau tidak ada -> null', () => {
    assert.equal(enumDariQuery({}, 'status', daftar, 'x'), null)
    assert.equal(enumDariQuery({ status: '  ' }, 'status', daftar, 'x'), null)
  })
  it('nilai sah dikembalikan', () => assert.equal(enumDariQuery({ status: ' Baru ' }, 'status', daftar, 'x'), 'Baru'))
  it('nilai salah -> 422 dengan pesan', () => {
    assert.throws(() => enumDariQuery({ status: 'Lain' }, 'status', daftar, 'Status tidak valid'),
      (e) => e.status === 422 && e.message === 'Status tidak valid')
  })
})
