<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/* [MODUL 8] POST /uploads : unggah satu gambar (multipart/form-data, kolom "file"). Semua peran yang sudah login.
   Balas 201 { data: { url, size, type } }. "url" dipakai di PUT /me (photo), POST /tickets (attachment),
   dan PUT /tickets/{id}/photos (before, after). Aturan dan pesan sama dengan server tiruan frontend (public/helpdesk/api/api-tiruan.js).

   - Jenis berkas ditentukan dari ISI berkas (finfo dan getimagesize), bukan dari nama atau Content-Type kiriman klien.
   - Nama berkas diacak 32 karakter hex, jadi tidak bisa ditebak dan tidak bisa menimpa berkas lain.
   - Disimpan di public/uploads supaya langsung dilayani web server (tanpa php artisan storage:link).
     Alamat yang dibalas absolut (https://api.contoh.co.id/uploads/xxxx.jpg), karena frontend dan API bisa beda domain. */
class UploadController extends Controller
{
    private const JENIS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const MAKS_BYTE = 5 * 1024 * 1024;

    public function simpan(Request $request): JsonResponse
    {
        $berkas = $request->file('file');

        if (! $berkas instanceof UploadedFile) {
            // Tidak ada kolom file. Kalau PHP menolak berkas besar (post_max_size), kolom ini juga kosong.
            throw new ApiException(422, 'VALIDASI', 'Pilih foto dulu');
        }
        if (in_array($berkas->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiException(422, 'VALIDASI', 'Ukuran foto maksimal 5 MB');
        }
        if (! $berkas->isValid()) {
            throw new ApiException(422, 'VALIDASI', 'Foto gagal diunggah, coba lagi');
        }
        if ($berkas->getSize() > self::MAKS_BYTE) {
            throw new ApiException(422, 'VALIDASI', 'Ukuran foto maksimal 5 MB');
        }

        $jalur = $berkas->getRealPath();
        $jenis = (new \finfo(FILEINFO_MIME_TYPE))->file($jalur);
        if (! isset(self::JENIS[$jenis]) || @getimagesize($jalur) === false) {
            throw new ApiException(422, 'VALIDASI', 'Foto harus berformat JPG, PNG, WEBP, atau GIF');
        }

        $folder = public_path('uploads');
        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new ApiException(500, 'SERVER_ERROR', 'Folder public/uploads tidak bisa dibuat');
        }

        $nama = bin2hex(random_bytes(16)).'.'.self::JENIS[$jenis];
        try {
            $berkas->move($folder, $nama);
        } catch (\Throwable $e) {
            report($e);
            throw new ApiException(500, 'SERVER_ERROR', 'Foto tidak bisa disimpan. Periksa izin folder public/uploads.');
        }
        @chmod($folder.'/'.$nama, 0644);

        return response()->json(['data' => [
            'url' => $request->getSchemeAndHttpHost().'/uploads/'.$nama,
            'size' => (int) filesize($folder.'/'.$nama),
            'type' => $jenis,
        ]], 201);
    }
}
