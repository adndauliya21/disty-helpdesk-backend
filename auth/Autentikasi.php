<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Exceptions\TokenException;
use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* [MODUL 6] MIDDLEWARE AUTENTIKASI, padanan autentikasi() di src/middleware/auth.js.
   "Siapa kamu?" -> 401 bila tidak ada token atau token tidak valid.
   Dipasang di rute lewat nama pendek 'jwt' (lihat laravel/bootstrap/app.php dan api/api.php).
   Data pengguna dari token ditaruh di $request->attributes['pengguna'] (padanan request.pengguna). */
class Autentikasi
{
    public function handle(Request $request, Closure $next): Response
    {
        $bagian = explode(' ', (string) $request->header('Authorization', ''));
        $skema = $bagian[0];
        $token = $bagian[1] ?? '';

        if (strtolower($skema) !== 'bearer' || $token === '') {
            throw new ApiException(401, 'TIDAK_TERAUTENTIKASI', 'Silakan masuk terlebih dahulu');
        }

        try {
            $payload = Jwt::periksa($token, config('disty.jwt.rahasia'));
        } catch (TokenException $e) {
            $kedaluwarsa = $e->kode === 'KEDALUWARSA';
            throw new ApiException(
                401,
                $kedaluwarsa ? 'TOKEN_KEDALUWARSA' : 'TOKEN_TIDAK_VALID',
                $kedaluwarsa ? 'Sesi sudah berakhir, silakan masuk lagi' : 'Token tidak valid',
            );
        }

        $request->attributes->set('pengguna', [
            'id' => $payload['sub'] ?? null,
            'name' => $payload['name'] ?? null,
            'role' => $payload['role'] ?? null,
            'instansiCode' => $payload['instansiCode'] ?? null,
            'technician' => $payload['technician'] ?? null,
        ]);

        return $next($request);
    }
}
