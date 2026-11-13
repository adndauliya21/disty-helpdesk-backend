// DAFTAR RUTE. Sama persis dengan api/api.php di Laravel: hanya /ping, /auth/login, dan aktivasi yang terbuka,
// sisanya wajib login (autentikasi), sebagian dibatasi per peran (peran(...)).
// Metode HTTP (REST):  GET baca | POST buat | PUT ganti | PATCH ubah sebagian | DELETE hapus
import { Router } from './core/router.js'
import { autentikasi, peran } from './middleware/auth.js'
import * as auth from './controllers/auth.js'
import * as konsultasi from './controllers/konsultasi.js'
import * as referensi from './controllers/referensi.js'
import * as survei from './controllers/survei.js'
import * as teknisi from './controllers/teknisi.js'
import * as tickets from './controllers/tickets.js'
import * as upload from './controllers/upload.js'

export function buatRouter() {
  const r = new Router()
  const login = [autentikasi]
  const admin = [autentikasi, peran('Admin')]
  const klien = [autentikasi, peran('Klien')]
  const teknisiSaja = [autentikasi, peran('Teknisi')]

  // Terbuka
  r.tambah('GET', '/ping', [], referensi.ping)
  r.tambah('POST', '/auth/login', [], auth.login)
  r.tambah('POST', '/auth/activation/request', [], auth.mintaKodeAktivasi)
  r.tambah('POST', '/auth/activation/verify', [], auth.verifikasiKodeAktivasi)
  // Beranda publik: nomor teknisi supaya klien bisa menelepon langsung (hanya nama, spesialisasi, dan nomor teknisi aktif).
  r.tambah('GET', '/contacts/technicians', [], teknisi.kontak)

  // Semua peran yang sudah login
  r.tambah('GET', '/auth/me', login, auth.me)
  r.tambah('POST', '/auth/logout', login, auth.logout)
  r.tambah('PUT', '/me', login, auth.ubahProfil)
  r.tambah('PUT', '/me/password', login, auth.ubahSandi)
  r.tambah('POST', '/uploads', login, upload.simpan)

  // Aduan
  r.tambah('GET', '/tickets', login, tickets.index)
  r.tambah('GET', '/tickets/:id', login, tickets.show)
  r.tambah('POST', '/tickets', klien, tickets.store)
  // Klien menelepon teknisi langsung (nomor di beranda), teknisi mencatat aduannya: tanpa penugasan admin, status langsung Diproses.
  r.tambah('POST', '/tickets/phone', teknisiSaja, tickets.storeTelepon)
  r.tambah('PATCH', '/tickets/:id', [autentikasi, peran('Admin', 'Teknisi')], tickets.ubah)
  r.tambah('POST', '/tickets/:id/assignments', admin, tickets.tugaskan)
  r.tambah('POST', '/tickets/:id/logs', teknisiSaja, tickets.catatTindakan)
  r.tambah('PUT', '/tickets/:id/solution', teknisiSaja, tickets.selesaikan)
  r.tambah('PUT', '/tickets/:id/verification', admin, tickets.verifikasi)
  r.tambah('PUT', '/tickets/:id/rating', klien, tickets.beriNilai)
  r.tambah('PUT', '/tickets/:id/photos', teknisiSaja, tickets.foto)

  // Konsultasi dan kuota jadwal
  r.tambah('GET', '/consultations', login, konsultasi.index)
  r.tambah('POST', '/consultations', klien, konsultasi.store)
  r.tambah('PATCH', '/consultations/:id', admin, konsultasi.ubahStatus)
  r.tambah('PUT', '/consultations/:id/technician', admin, konsultasi.tugaskan)
  r.tambah('POST', '/consultations/:id/messages', admin, konsultasi.balas)
  r.tambah('GET', '/consultation-quota', login, konsultasi.lihatKuota)
  r.tambah('PUT', '/consultation-quota', admin, konsultasi.simpanKuota)

  // Survei dan data referensi
  r.tambah('GET', '/surveys/me', login, survei.lihat)
  r.tambah('PUT', '/surveys/me', klien, survei.simpan)
  r.tambah('GET', '/knowledge-base', login, referensi.basisPengetahuan)
  r.tambah('GET', '/knowledge-base/:judul', login, referensi.artikel)
  r.tambah('GET', '/solutions', login, referensi.solusi)
  r.tambah('GET', '/institutions', login, referensi.instansi)

  // Teknisi
  r.tambah('GET', '/technicians', login, teknisi.index)
  r.tambah('GET', '/technicians/:name', login, teknisi.show)
  r.tambah('POST', '/technicians', admin, teknisi.store)
  r.tambah('PUT', '/technicians/:name', admin, teknisi.update)
  r.tambah('DELETE', '/technicians/:name', admin, teknisi.destroy)

  return r
}
