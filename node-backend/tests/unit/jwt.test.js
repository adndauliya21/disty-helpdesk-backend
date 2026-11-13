import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import { describe, it } from 'node:test'
import { buatToken, periksaToken } from '../../src/auth/jwt.js'
import { GalatToken } from '../../src/core/http-error.js'

const RAHASIA = 'rahasia-uji'
const b64 = (nilai) => Buffer.from(typeof nilai === 'string' ? nilai : JSON.stringify(nilai)).toString('base64url')
const tandai = (isi) => createHmac('sha256', RAHASIA).update(isi).digest('base64url')
const rakit = (header, payload) => {
  const isi = `${b64(header)}.${b64(payload)}`
  return `${isi}.${tandai(isi)}`
}
const kode = (token) => {
  try { periksaToken(token, RAHASIA); return null } catch (e) { return e instanceof GalatToken ? e.kode : `bukan GalatToken: ${e}` }
}

describe('JWT', () => {
  it('token buatan sendiri bisa dibaca kembali', () => {
    const payload = periksaToken(buatToken({ sub: 7, name: 'Rina', role: 'Klien' }, RAHASIA, 60), RAHASIA)
    assert.equal(payload.sub, 7)
    assert.equal(payload.role, 'Klien')
    assert.equal(payload.exp - payload.iat, 60)
  })

  it('tiga bagian dipisah titik dan header berisi HS256', () => {
    const bagian = buatToken({ sub: 1 }, RAHASIA, 60).split('.')
    assert.equal(bagian.length, 3)
    assert.deepEqual(JSON.parse(Buffer.from(bagian[0], 'base64url')), { alg: 'HS256', typ: 'JWT' })
  })

  it('rahasia berbeda ditolak (TIDAK_VALID)', () => {
    assert.equal(kode(buatToken({ sub: 1 }, 'rahasia-lain', 60)), 'TIDAK_VALID')
  })

  it('payload yang diubah ditolak walau tanda tangan lama dipakai', () => {
    const [h, , t] = buatToken({ sub: 1, role: 'Klien' }, RAHASIA, 60).split('.')
    const palsu = `${h}.${b64({ sub: 1, role: 'Admin', exp: 9999999999 })}.${t}`
    assert.equal(kode(palsu), 'TIDAK_VALID')
  })

  it('token kedaluwarsa dibedakan (KEDALUWARSA)', () => {
    const lampau = Math.floor(Date.now() / 1000) - 10
    assert.equal(kode(rakit({ alg: 'HS256', typ: 'JWT' }, { sub: 1, exp: lampau })), 'KEDALUWARSA')
  })

  it('exp tidak ada atau bukan bilangan bulat dianggap kedaluwarsa', () => {
    assert.equal(kode(rakit({ alg: 'HS256', typ: 'JWT' }, { sub: 1 })), 'KEDALUWARSA')
    assert.equal(kode(rakit({ alg: 'HS256', typ: 'JWT' }, { sub: 1, exp: '9999999999' })), 'KEDALUWARSA')
  })

  it('algoritma "none" ditolak (serangan klasik JWT)', () => {
    const isi = `${b64({ alg: 'none', typ: 'JWT' })}.${b64({ sub: 1, role: 'Admin', exp: 9999999999 })}`
    assert.equal(kode(`${isi}.`), 'TIDAK_VALID')
    assert.equal(kode(`${isi}.${tandai(isi)}`), 'TIDAK_VALID')
  })

  it('algoritma lain (HS512) ditolak', () => {
    assert.equal(kode(rakit({ alg: 'HS512', typ: 'JWT' }, { sub: 1, exp: 9999999999 })), 'TIDAK_VALID')
  })

  it('bentuk rusak ditolak', () => {
    for (const token of ['', 'abc', 'a.b', 'a.b.c.d', 'aaa.bbb.ccc', '!!!.@@@.###', `${b64('[]')}.${b64('[]')}.x`]) {
      assert.equal(kode(token), 'TIDAK_VALID', `token "${token}"`)
    }
  })

  it('tanda tangan dengan karakter di luar base64url ditolak', () => {
    const [h, p] = buatToken({ sub: 1 }, RAHASIA, 60).split('.')
    assert.equal(kode(`${h}.${p}.abc+/=`), 'TIDAK_VALID')
  })
})
