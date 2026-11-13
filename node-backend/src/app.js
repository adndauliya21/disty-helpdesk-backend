// TITIK RAKIT APLIKASI: satu fungsi yang menerima setiap permintaan HTTP dan menentukan jawabannya.
// Alurnya:
//   1. Alamat /api/v1/...   -> cocokkan rute (404 atau 405 bila tidak cocok) -> middleware (401, 403) -> handler -> JSON
//   2. Alamat lain (GET)    -> sajikan berkas frontend dari folder public
//   3. Galat apa pun        -> diubah ke JSON { error: { code, message, details } }, kesalahan tak terduga jadi 500
// Padanan bootstrap/app.php di Laravel.
import { config } from './config.js'
import { bacaBerkas, bacaJson } from './core/body.js'
import { GalatHttp } from './core/http-error.js'
import { layaniStatis } from './core/static.js'
import { buatRouter } from './routes.js'

const AWALAN_API = '/api/v1'
const METODE_BOLEH = 'GET, POST, PUT, PATCH, DELETE, OPTIONS'

/** Header CORS: browser hanya mengizinkan frontend memanggil API bila server membalas dengan header ini. */
function headerCors(req) {
  const asal = req.headers.origin
  const header = { 'Access-Control-Expose-Headers': 'Location' }
  if (config.corsOrigin.includes('*')) {
    header['Access-Control-Allow-Origin'] = '*'
  } else if (asal && config.corsOrigin.includes(asal)) {
    header['Access-Control-Allow-Origin'] = asal
    header.Vary = 'Origin'
  }
  return header
}

function kirim(req, res, { status = 200, body, headers = {} }, tambahan = {}) {
  const semua = { ...tambahan, ...headers, 'X-Content-Type-Options': 'nosniff' }
  if (body === undefined) {
    res.writeHead(status, { ...semua, 'Content-Length': 0 })
    res.end()
    return
  }
  const isi = JSON.stringify(body)
  res.writeHead(status, {
    ...semua,
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(isi),
  })
  res.end(req.method === 'HEAD' ? undefined : isi)
}

/** Ubah galat apa pun menjadi balasan JSON. Hanya GalatHttp yang detailnya boleh dilihat klien. */
function balasanGalat(galat) {
  if (galat instanceof GalatHttp) return { status: galat.status, body: galat.keJson() }
  console.error(galat)   // kesalahan tak terduga: dicatat di konsol, detailnya tidak dibocorkan ke klien
  return {
    status: 500,
    body: { error: { code: 'SERVER_ERROR', message: 'Terjadi gangguan di server. Coba lagi beberapa saat lagi.' } },
  }
}

const tidakAda = () => new GalatHttp(404, 'TIDAK_DITEMUKAN', 'Alamat tidak ditemukan')

export function buatPenangan() {
  const router = buatRouter()

  async function tanganiApi(req, res, path, query) {
    const cors = headerCors(req)
    if (req.method === 'OPTIONS') {
      // Preflight: browser bertanya dulu "boleh?" sebelum mengirim permintaan sungguhan.
      kirim(req, res, { status: 204 }, {
        ...cors,
        'Access-Control-Allow-Methods': METODE_BOLEH,
        'Access-Control-Allow-Headers': 'Authorization, Content-Type',
      })
      return
    }
    try {
      const metode = req.method === 'HEAD' ? 'GET' : req.method
      const rutePath = path.slice(AWALAN_API.length) || '/'
      const cocok = router.cocokkan(metode, rutePath)
      if (!cocok) throw tidakAda()
      if (cocok.diperbolehkan) {
        throw new GalatHttp(405, 'METODE_TIDAK_DIPERBOLEHKAN', `Metode ${req.method} tidak didukung untuk alamat ini`, {
          diperbolehkan: cocok.diperbolehkan,
        })
      }

      // Isi permintaan dibaca hanya bila handler memintanya, setelah login dan peran lolos (sama dengan Laravel).
      let isi
      const ctx = {
        req, res, query, params: cocok.params, pengguna: null,
        isi: () => (isi ??= bacaJson(req)),
        berkas: () => bacaBerkas(req),
      }
      for (const middleware of cocok.rute.middleware) await middleware(ctx)
      kirim(req, res, await cocok.rute.handler(ctx), cors)
    } catch (galat) {
      kirim(req, res, balasanGalat(galat), cors)
    } finally {
      if (!req.complete) req.resume()   // buang sisa body yang tidak dibaca supaya sambungan tidak menggantung
    }
  }

  return async function tangani(req, res) {
    try {
      const url = new URL(req.url, 'http://localhost')
      const query = {}
      for (const [kunci, nilai] of url.searchParams) query[kunci] = nilai   // nilai terakhir menang, seperti PHP
      const path = url.pathname.length > 1 ? url.pathname.replace(/\/+$/, '') : url.pathname

      if (path === AWALAN_API || path.startsWith(`${AWALAN_API}/`)) {
        await tanganiApi(req, res, path, query)
        return
      }
      if ((req.method === 'GET' || req.method === 'HEAD') && !path.startsWith('/api/') && (await layaniStatis(req, res, url.pathname))) {
        return
      }
      // Navigasi browser ke alamat yang tidak dikenal (mis. /admin/dashboard) kembali ke halaman awal. API tetap 404 JSON.
      if ((req.method === 'GET' || req.method === 'HEAD') && !path.startsWith('/api/') && String(req.headers.accept || '').includes('text/html')) {
        res.writeHead(302, { Location: '/' })
        res.end()
        return
      }
      kirim(req, res, balasanGalat(tidakAda()), headerCors(req))
      if (!req.complete) req.resume()
    } catch (galat) {
      if (!res.headersSent) kirim(req, res, balasanGalat(galat))
      else res.end()
    }
  }
}
