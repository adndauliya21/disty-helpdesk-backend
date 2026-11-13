// Menyajikan frontend (index.html, css, js, gambar) dan foto unggahan dari folder public.
// Di Laravel tugas ini dikerjakan web server (Apache atau Nginx). Di Node kita kerjakan sendiri
// supaya satu perintah "npm start" sudah cukup untuk menjalankan aplikasi utuh.
import { createReadStream } from 'node:fs'
import { stat } from 'node:fs/promises'
import path from 'node:path'
import { config } from '../config.js'

const JENIS = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.map': 'application/json',
}

/** Layani berkas statis. Mengembalikan true bila berkas ditemukan dan dikirim, false bila tidak ada. */
export async function layaniStatis(req, res, pathname) {
  let relatif
  try {
    relatif = decodeURIComponent(pathname)
  } catch {
    return false
  }
  if (relatif.includes('\0')) return false
  if (relatif === '/') relatif = '/index.html'

  const bagian = relatif.split('/').filter(Boolean)
  // Tolak berkas tersembunyi (.env, .htaccess), skrip PHP, dan naik folder (..).
  if (bagian.some((b) => b.startsWith('.') || b === '..') || /\.php$/i.test(relatif)) return false

  const jalur = path.join(config.folderPublic, ...bagian)
  if (!jalur.startsWith(config.folderPublic + path.sep)) return false

  let info
  try {
    info = await stat(jalur)
  } catch {
    return false
  }
  if (!info.isFile()) return false

  const jenis = JENIS[path.extname(jalur).toLowerCase()] ?? 'application/octet-stream'
  res.writeHead(200, {
    'Content-Type': jenis,
    'Content-Length': info.size,
    'X-Content-Type-Options': 'nosniff',
    'Cache-Control': jenis.startsWith('text/html') ? 'no-cache' : 'public, max-age=3600',
  })
  if (req.method === 'HEAD') res.end()
  else createReadStream(jalur).pipe(res)
  return true
}
