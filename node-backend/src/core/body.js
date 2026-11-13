// Membaca isi permintaan: JSON biasa dan unggahan multipart (foto).
import Busboy from 'busboy'
import { config } from '../config.js'
import { GalatHttp } from './http-error.js'

const terlaluBesar = () => new GalatHttp(413, 'BODY_TERLALU_BESAR', 'Data yang dikirim terlalu besar')

/** Kumpulkan seluruh body sebagai Buffer. Bila melebihi batas, sisanya dibuang dan hasilnya null (lalu 413). */
function bacaMentah(req, batas) {
  return new Promise((selesai, gagal) => {
    const potongan = []
    let total = 0
    let berlebih = false
    req.on('data', (bagian) => {
      total += bagian.length
      if (total > batas) berlebih = true
      else potongan.push(bagian)
    })
    req.on('end', () => selesai(berlebih ? null : Buffer.concat(potongan)))
    req.on('error', gagal)
  })
}

/** Isi body sebagai objek. Body kosong -> {}. Bukan objek JSON -> 400 JSON_RUSAK. Terlalu besar -> 413. */
export async function bacaJson(req) {
  const mentah = await bacaMentah(req, config.batasBodyByte)
  if (mentah === null) throw terlaluBesar()
  const teks = mentah.toString('utf8').trim()
  if (teks === '') return {}
  let nilai
  try {
    nilai = JSON.parse(teks)
  } catch {
    throw new GalatHttp(400, 'JSON_RUSAK', 'Format JSON yang dikirim tidak valid')
  }
  // Hanya objek JSON yang diterima (bukan larik, angka, atau teks).
  if (nilai === null || typeof nilai !== 'object' || Array.isArray(nilai)) {
    throw new GalatHttp(400, 'JSON_RUSAK', 'Format JSON yang dikirim tidak valid')
  }
  return nilai
}

/** Ambil satu berkas dari kolom multipart "file". Hasil: { isi: Buffer, terpotong } atau null bila tidak ada. */
export function bacaBerkas(req) {
  return new Promise((selesai, gagal) => {
    if (!/^multipart\/form-data/i.test(req.headers['content-type'] ?? '')) {
      req.resume()
      selesai(null)
      return
    }
    let parser
    try {
      parser = Busboy({ headers: req.headers, limits: { files: 1, fileSize: config.batasUnggahByte, fields: 10, parts: 12 } })
    } catch {
      req.resume()
      selesai(null)
      return
    }
    let hasil = null
    let totalByte = 0
    const batasTotal = config.batasUnggahByte + 1_000_000   // berkas + sedikit ruang untuk header multipart
    req.on('data', (bagian) => {
      totalByte += bagian.length
      if (totalByte > batasTotal) {
        req.unpipe(parser)
        req.resume()
        gagal(terlaluBesar())
      }
    })
    parser.on('file', (nama, aliran) => {
      const potongan = []
      let terpotong = false
      aliran.on('data', (b) => potongan.push(b))
      aliran.on('limit', () => { terpotong = true })
      aliran.on('end', () => {
        if (nama === 'file' && hasil === null) hasil = { isi: Buffer.concat(potongan), terpotong }
      })
    })
    parser.on('error', () => selesai(null))
    parser.on('close', () => selesai(hasil))
    req.pipe(parser)
  })
}
