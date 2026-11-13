// Pengaturan aplikasi, dibaca dari .env (lewat process.loadEnvFile bawaan Node) dan variabel lingkungan.
// Padanan config/disty.php dan config/database.php di Laravel.
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const akarProyek = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

try {
  process.loadEnvFile(path.join(akarProyek, '.env'))
} catch {
  // Tidak ada .env: pakai variabel lingkungan atau nilai bawaan di bawah.
}

const env = process.env
const angka = (nilai, bawaan) => {
  const hasil = Number.parseInt(nilai ?? '', 10)
  return Number.isFinite(hasil) ? hasil : bawaan
}

export const config = {
  port: angka(env.PORT, 3000),
  db: {
    host: env.DB_HOST ?? '127.0.0.1',
    port: angka(env.DB_PORT, 3306),
    database: env.DB_DATABASE ?? 'disty_helpdesk',
    user: env.DB_USERNAME ?? 'root',
    password: env.DB_PASSWORD ?? '',
  },
  jwt: {
    // Nilai bawaan HANYA untuk pengembangan. Di produksi wajib diganti lewat JWT_SECRET.
    rahasia: env.JWT_SECRET || 'rahasia-pengembangan-jangan-dipakai-di-produksi',
    berlakuDetik: angka(env.JWT_EXPIRES_SECONDS, 8 * 3600),
  },
  domainStaf: env.DOMAIN_STAF || 'perusahaan.id',
  corsOrigin: (env.CORS_ORIGIN ?? '*').split(',').map((o) => o.trim()).filter(Boolean),
  folderPublic: path.resolve(akarProyek, env.PUBLIC_DIR || '../laravel/public'),
  bcryptRounds: angka(env.BCRYPT_ROUNDS, 12),
  batasBodyByte: 1_000_000,
  batasUnggahByte: 5 * 1024 * 1024,
}
