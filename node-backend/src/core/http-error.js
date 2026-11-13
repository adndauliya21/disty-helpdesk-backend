// Galat yang "sudah diperkirakan" (401, 404, 422, dan seterusnya). Dilempar dari mana saja,
// lalu app.js mengubahnya menjadi JSON sesuai kontrak:
//   { "error": { "code": "KODE", "message": "...", "details": ... } }
// Padanan App\Exceptions\ApiException di Laravel.
export class GalatHttp extends Error {
  constructor(status, kode, pesan, rincian = null) {
    super(pesan)
    this.status = status
    this.kode = kode
    this.rincian = rincian
  }

  keJson() {
    const error = { code: this.kode, message: this.message }
    if (this.rincian !== null) error.details = this.rincian
    return { error }
  }
}

/** Galat token JWT. kode: 'KEDALUWARSA' atau 'TIDAK_VALID'. */
export class GalatToken extends Error {
  constructor(kode, pesan) {
    super(pesan)
    this.kode = kode
  }
}

export const tidakDitemukan = (apa) => new GalatHttp(404, 'TIDAK_DITEMUKAN', `${apa} tidak ditemukan`)
