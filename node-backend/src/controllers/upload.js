// POST /uploads : unggah satu gambar (multipart/form-data, kolom "file"). Semua peran yang sudah login.
// Balas 201 { data: { url, size, type } }. "url" dipakai di PUT /me (photo), POST /tickets (attachment),
// dan PUT /tickets/:id/photos (before, after). Padanan UploadController di Laravel.
//   - Jenis berkas ditentukan dari ISI berkas (byte awal), bukan dari nama atau Content-Type kiriman klien.
//   - Nama berkas diacak 32 karakter hex, jadi tidak bisa ditebak dan tidak bisa menimpa berkas lain.
//   - Disimpan di folder public/uploads, dilayani langsung oleh server ini (atau web server bila Node ada di belakangnya).
import { randomBytes } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { config } from '../config.js'
import { GalatHttp } from '../core/http-error.js'

/** Kenali jenis gambar dari byte awalnya. Mengembalikan { mime, ekstensi } atau null. */
export function kenaliGambar(b) {
  if (b.length >= 3 && b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return { mime: 'image/jpeg', ekstensi: 'jpg' }
  if (b.length >= 24 && b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))
    && b.subarray(12, 16).toString('latin1') === 'IHDR') return { mime: 'image/png', ekstensi: 'png' }
  if (b.length >= 10 && ['GIF87a', 'GIF89a'].includes(b.subarray(0, 6).toString('latin1'))) return { mime: 'image/gif', ekstensi: 'gif' }
  if (b.length >= 12 && b.subarray(0, 4).toString('latin1') === 'RIFF' && b.subarray(8, 12).toString('latin1') === 'WEBP') {
    return { mime: 'image/webp', ekstensi: 'webp' }
  }
  return null
}

export async function simpan(ctx) {
  const berkas = await ctx.berkas()
  if (berkas === null) throw new GalatHttp(422, 'VALIDASI', 'Pilih foto dulu')
  if (berkas.terpotong || berkas.isi.length > config.batasUnggahByte) throw new GalatHttp(422, 'VALIDASI', 'Ukuran foto maksimal 5 MB')

  const jenis = kenaliGambar(berkas.isi)
  if (!jenis) throw new GalatHttp(422, 'VALIDASI', 'Foto harus berformat JPG, PNG, WEBP, atau GIF')

  const folder = path.join(config.folderPublic, 'uploads')
  const nama = `${randomBytes(16).toString('hex')}.${jenis.ekstensi}`
  try {
    await mkdir(folder, { recursive: true })
    await writeFile(path.join(folder, nama), berkas.isi, { mode: 0o644 })
  } catch (galat) {
    console.error('Foto tidak bisa disimpan:', galat)
    throw new GalatHttp(500, 'SERVER_ERROR', 'Foto tidak bisa disimpan. Periksa izin folder public/uploads.')
  }

  // Alamat absolut, karena frontend dan API bisa beda domain.
  const skema = String(ctx.req.headers['x-forwarded-proto'] ?? 'http').split(',')[0].trim() || 'http'
  const host = ctx.req.headers.host ?? `localhost:${config.port}`
  return { status: 201, body: { data: { url: `${skema}://${host}/uploads/${nama}`, size: berkas.isi.length, type: jenis.mime } } }
}
