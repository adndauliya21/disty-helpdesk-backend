// Pintu masuk: jalankan dengan  npm start  (atau  node src/server.js).
import { createServer } from 'node:http'
import { buatPenangan } from './app.js'
import { config } from './config.js'
import { pool } from './db.js'

const server = createServer(buatPenangan())

// Beri batas waktu supaya sambungan yang menggantung tidak menumpuk.
server.requestTimeout = 30_000
server.headersTimeout = 15_000

server.listen(config.port, () => {
  console.log(`Disty HelpDesk (Node.js) berjalan di http://localhost:${config.port}`)
  console.log(`API   : http://localhost:${config.port}/api/v1/ping`)
  console.log(`Folder: ${config.folderPublic}`)
  if (config.jwt.rahasia === 'rahasia-pengembangan-jangan-dipakai-di-produksi') {
    console.warn('PERINGATAN: JWT_SECRET masih nilai bawaan. Ganti di .env sebelum dipakai di produksi.')
  }
})

// Berhenti dengan rapi: selesaikan permintaan yang sedang berjalan, lalu tutup koneksi database.
async function berhenti(sinyal) {
  console.log(`\n${sinyal} diterima, menutup server...`)
  server.close(async () => {
    await pool.end()
    process.exit(0)
  })
  setTimeout(() => process.exit(1), 10_000).unref()
}
process.on('SIGINT', () => berhenti('SIGINT'))
process.on('SIGTERM', () => berhenti('SIGTERM'))
