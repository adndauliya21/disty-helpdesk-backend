// Hash kata sandi dengan bcrypt. Laravel menyimpan hash berawalan $2y$, sedangkan bcryptjs menghasilkan $2b$.
// Algoritmanya sama persis, hanya awalannya yang beda. Kita samakan ke $2y$ supaya hash buatan Node tetap
// lolos pemeriksaan Hash::check di Laravel (yang hanya mengenali $2y$), dan hash Laravel tetap terbaca di sini.
import bcrypt from 'bcryptjs'
import { config } from '../config.js'

// Hash palsu yang valid: dipakai agar waktu respons sama baik email ada maupun tidak
// (mencegah penebakan email lewat lama respons).
export const HASH_PALSU = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'

export async function buatHash(kataSandi) {
  const hash = await bcrypt.hash(kataSandi, config.bcryptRounds)
  return hash.replace(/^\$2[ab]\$/, '$2y$')
}

export async function cocok(kataSandi, hash) {
  try {
    return await bcrypt.compare(kataSandi, hash)
  } catch {
    return false
  }
}
