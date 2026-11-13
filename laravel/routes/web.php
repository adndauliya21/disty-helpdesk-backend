<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/* Alamat yang tidak dikenal (mis. /admin/dashboard diketik langsung oleh Klien) dialihkan ke halaman awal.
   Hanya untuk navigasi browser. Permintaan API atau JSON tetap mendapat 404 JSON sesuai kontrak. */
Route::fallback(function (Request $request) {
    if ($request->is('api/*') || $request->expectsJson()) {
        throw new NotFoundHttpException;
    }

    return redirect('/');
});
