<?php

namespace App\Support;

use App\Exceptions\TokenException;

/* [MODUL 6] JWT (JSON Web Token) HS256, padanan src/auth/jwt.js. Ditulis sendiri supaya terlihat cara kerjanya
   (tanpa paket tambahan, seperti di Modul 5).
   Token = tiga bagian dipisah titik:   header.payload.tanda-tangan   (masing-masing base64url)
   - header  : {"alg":"HS256","typ":"JWT"}
   - payload : data pengguna + waktu terbit (iat) + waktu kedaluwarsa (exp)
   - tanda tangan : HMAC-SHA256(header.payload, rahasia).
   Payload hanya di-encode, BUKAN dienkripsi: jangan taruh kata sandi atau data rahasia di dalamnya. */
final class Jwt
{
    public static function buat(array $payload, string $rahasia, int $berlakuDetik): string
    {
        $sekarang = time();
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $isi = self::b64(json_encode(
            [...$payload, 'iat' => $sekarang, 'exp' => $sekarang + $berlakuDetik],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return "{$header}.{$isi}.".self::b64(self::tandaTangan("{$header}.{$isi}", $rahasia));
    }

    /** @throws TokenException */
    public static function periksa(string $token, string $rahasia): array
    {
        $bagian = explode('.', $token);
        if (count($bagian) !== 3) {
            throw new TokenException('TIDAK_VALID', 'Token rusak');
        }
        [$header, $isi, $ttd] = $bagian;

        $dataHeader = json_decode(self::dekode($header) ?? '', true);
        $payload = json_decode(self::dekode($isi) ?? '', true);
        if (! is_array($dataHeader) || ! is_array($payload)) {
            throw new TokenException('TIDAK_VALID', 'Token rusak');
        }

        // Tolak algoritma lain (termasuk "none"): hanya HS256 yang kita terbitkan.
        if (($dataHeader['alg'] ?? null) !== 'HS256') {
            throw new TokenException('TIDAK_VALID', 'Algoritma token tidak didukung');
        }

        $seharusnya = self::tandaTangan("{$header}.{$isi}", $rahasia);
        $diterima = self::dekode($ttd);
        if ($diterima === null || ! hash_equals($seharusnya, $diterima)) {   // pembanding waktu-tetap
            throw new TokenException('TIDAK_VALID', 'Tanda tangan token tidak cocok');
        }

        if (! isset($payload['exp']) || ! is_int($payload['exp']) || $payload['exp'] <= time()) {
            throw new TokenException('KEDALUWARSA', 'Token sudah kedaluwarsa');
        }

        return $payload;
    }

    private static function tandaTangan(string $isi, string $rahasia): string
    {
        return hash_hmac('sha256', $isi, $rahasia, true);
    }

    private static function b64(string $nilai): string
    {
        return rtrim(strtr(base64_encode($nilai), '+/', '-_'), '=');
    }

    private static function dekode(string $nilai): ?string
    {
        $hasil = base64_decode(strtr($nilai, '-_', '+/').str_repeat('=', (4 - strlen($nilai) % 4) % 4), true);

        return $hasil === false ? null : $hasil;
    }
}
