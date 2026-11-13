<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* [MODUL 6] MIDDLEWARE OTORISASI, padanan peran(...) di src/middleware/auth.js.
   "Boleh?" -> 403 bila sudah login tetapi perannya tidak diizinkan.
   Pemakaian di rute:  ->middleware('peran:Admin')  atau  ->middleware('peran:Admin,Teknisi')
   Harus dipasang SETELAH 'jwt'. */
class PeranDiizinkan
{
    public function handle(Request $request, Closure $next, string ...$diizinkan): Response
    {
        $peran = $request->attributes->get('pengguna')['role'] ?? null;

        if (! in_array($peran, $diizinkan, true)) {
            throw new ApiException(403, 'DILARANG', 'Kamu tidak punya akses untuk tindakan ini');
        }

        return $next($request);
    }
}
