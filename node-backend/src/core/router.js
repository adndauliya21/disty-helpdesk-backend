// Router kecil buatan sendiri (tanpa Express): mencocokkan metode HTTP + alamat ke handler.
// Pola memakai :nama untuk bagian alamat yang berubah, misalnya /tickets/:id/assignments.
// Padanan api/api.php di Laravel.
import { GalatHttp } from './http-error.js'

export class Router {
  #rute = []

  tambah(metode, pola, middleware, handler) {
    const nama = []
    const regex = new RegExp(
      `^${pola.replace(/:([A-Za-z]+)/g, (_, n) => (nama.push(n), '([^/]+)'))}$`,
    )
    this.#rute.push({ metode, regex, nama, middleware, handler })
  }

  /** Hasil: { rute, params } bila cocok, { diperbolehkan: [...] } bila alamat ada tapi metodenya salah, atau null. */
  cocokkan(metode, path) {
    const diperbolehkan = []
    for (const rute of this.#rute) {
      const hasil = rute.regex.exec(path)
      if (!hasil) continue
      if (rute.metode === metode) {
        const params = {}
        rute.nama.forEach((n, i) => {
          try {
            params[n] = decodeURIComponent(hasil[i + 1])
          } catch {
            // Tanda % yang tidak berpasangan dengan kode sah (mis. %E0%A4%A) adalah permintaan yang rusak, bukan data tidak ada.
            throw new GalatHttp(400, 'GALAT_HTTP', 'Alamat mengandung penulisan % yang tidak valid')
          }
        })
        return { rute, params }
      }
      diperbolehkan.push(rute.metode)
    }
    return diperbolehkan.length ? { diperbolehkan: [...new Set(diperbolehkan)] } : null
  }
}
