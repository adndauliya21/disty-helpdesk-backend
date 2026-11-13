<?php

use App\Http\Controllers\AlurAduanController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InfoController;
use App\Http\Controllers\KonsultasiController;
use App\Http\Controllers\KuotaKonsultasiController;
use App\Http\Controllers\ReferensiController;
use App\Http\Controllers\SurveiController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/* [MODUL 6] DAFTAR RUTE. Path di bawah otomatis diawali /api/v1 (lihat withRouting di bootstrap/app.php).
   Sama persis dengan src/routes.js di backend Node (Modul 5): hanya /ping dan /auth/login yang terbuka,
   sisanya wajib login ('jwt'), sebagian dibatasi per peran ('peran:...').
   [MODUL 8] Ditambah rute yang ada di docs/API-KONTRAK.md frontend: logout, aktivasi, PUT /me, dan alur aduan.
   [MODUL 8, tahap 2 bagian 1] Ditambah konsultasi dan kuota jadwal. Survei dan data referensi (/knowledge-base, /solutions, /institutions) di tahap berikutnya.

   Metode HTTP (REST):  GET baca | POST buat | PUT ganti | PATCH ubah sebagian | DELETE hapus */

// Terbuka
Route::get('/ping', [InfoController::class, 'ping']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/activation/request', [AuthController::class, 'mintaKodeAktivasi']);
Route::post('/auth/activation/verify', [AuthController::class, 'verifikasiKodeAktivasi']);
// Beranda publik: nomor teknisi supaya klien bisa menelepon langsung (hanya nama, spesialisasi, dan nomor teknisi aktif).
Route::get('/contacts/technicians', [TechnicianController::class, 'kontak']);

// Wajib login
Route::middleware('jwt')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/me', [AuthController::class, 'ubahProfil']);
    Route::put('/me/password', [AuthController::class, 'ubahSandi']);
    Route::post('/uploads', [UploadController::class, 'simpan']);

    Route::get('/tickets', [TicketController::class, 'index']);
    Route::get('/tickets/{id}', [TicketController::class, 'show']);
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('peran:Klien');
    // Klien menelepon teknisi langsung (nomor di beranda), teknisi mencatat aduannya: tanpa penugasan admin, status langsung Diproses.
    Route::post('/tickets/phone', [TicketController::class, 'storeTelepon'])->middleware('peran:Teknisi');

    // [MODUL 8] Alur aduan
    Route::patch('/tickets/{id}', [AlurAduanController::class, 'ubah'])->middleware('peran:Admin,Teknisi');
    Route::post('/tickets/{id}/assignments', [AlurAduanController::class, 'tugaskan'])->middleware('peran:Admin');
    Route::post('/tickets/{id}/logs', [AlurAduanController::class, 'catatTindakan'])->middleware('peran:Teknisi');
    Route::put('/tickets/{id}/solution', [AlurAduanController::class, 'selesaikan'])->middleware('peran:Teknisi');
    Route::put('/tickets/{id}/verification', [AlurAduanController::class, 'verifikasi'])->middleware('peran:Admin');
    Route::put('/tickets/{id}/rating', [AlurAduanController::class, 'beriNilai'])->middleware('peran:Klien');
    Route::put('/tickets/{id}/photos', [AlurAduanController::class, 'foto'])->middleware('peran:Teknisi');

    // [MODUL 8] Konsultasi dan kuota jadwal
    Route::get('/consultations', [KonsultasiController::class, 'index']);
    Route::post('/consultations', [KonsultasiController::class, 'store'])->middleware('peran:Klien');
    Route::patch('/consultations/{id}', [KonsultasiController::class, 'ubahStatus'])->middleware('peran:Admin');
    Route::put('/consultations/{id}/technician', [KonsultasiController::class, 'tugaskan'])->middleware('peran:Admin');
    Route::put('/consultations/{id}/schedule', [KonsultasiController::class, 'ubahJadwal'])->middleware('peran:Admin');
    Route::post('/consultations/{id}/messages', [KonsultasiController::class, 'balas'])->middleware('peran:Admin');
    Route::get('/consultation-quota', [KuotaKonsultasiController::class, 'lihat']);
    Route::put('/consultation-quota', [KuotaKonsultasiController::class, 'simpan'])->middleware('peran:Admin');

    // [MODUL 8] Survei kepuasan dan data referensi
    Route::get('/surveys/me', [SurveiController::class, 'lihat']);
    Route::put('/surveys/me', [SurveiController::class, 'simpan'])->middleware('peran:Klien');
    Route::get('/knowledge-base', [ReferensiController::class, 'basisPengetahuan']);
    Route::get('/knowledge-base/{judul}', [ReferensiController::class, 'artikel']);
    Route::get('/solutions', [ReferensiController::class, 'solusi']);
    Route::get('/institutions', [ReferensiController::class, 'instansi']);

    Route::get('/technicians', [TechnicianController::class, 'index']);
    Route::get('/technicians/{name}', [TechnicianController::class, 'show']);

    Route::middleware('peran:Admin')->group(function () {
        Route::post('/technicians', [TechnicianController::class, 'store']);
        Route::put('/technicians/{name}', [TechnicianController::class, 'update']);
        Route::delete('/technicians/{name}', [TechnicianController::class, 'destroy']);
    });
});
