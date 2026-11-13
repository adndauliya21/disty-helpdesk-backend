<?php

namespace App\Support;

use App\Exceptions\ApiException;

/* [MODUL 8] Pemeriksa alamat foto. Alamat foto hanya boleh berasal dari POST /uploads, yaitu
   .../uploads/<32 karakter hex>.<jpg|png|webp|gif>. Dengan begitu klien tidak bisa menitipkan alamat sembarang
   (misalnya situs lain atau javascript:) ke kolom foto yang nanti ditampilkan di halaman orang lain.
   Padanan Cek::urlFoto() di api/ (PHP biasa) dan fotoSah() di helpdesk/api/api-tiruan.js. */
final class UrlFoto
{
    public const POLA = '#^(https?://[A-Za-z0-9.\-]+(:\d+)?)?/(?:[A-Za-z0-9_.\-]+/)*uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$#';

    public const PESAN = 'Foto tidak dikenal, silakan unggah ulang';

    /** null (hapus foto) atau alamat sah. Selain itu 422. */
    public static function periksa(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        if (! is_string($nilai) || strlen($nilai) > 255 || ! preg_match(self::POLA, $nilai)) {
            throw new ApiException(422, 'VALIDASI', self::PESAN, ['photo' => self::PESAN]);
        }

        return $nilai;
    }
}
