<?php

use App\Exceptions\ApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/* [MODUL 6] TITIK RAKIT APLIKASI. Di Modul 3 ini dikerjakan src/bootstrap.js, di Modul 2 src/bootstrap.php.
   Di Laravel cukup tiga hal: daftar rute, alias middleware, dan penanganan error terpusat. */
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../../api/api.php',
        apiPrefix: 'api/v1',          // semua rute di api/api.php otomatis diawali /api/v1
    )
    // [MODUL 6] Perintah artisan sekarang ada di auth dan bersama (folder setara laravel/), jadi harus didaftarkan satu per satu
    // (jalur bawaan app/Console/Commands tidak dipindai lagi).
    ->withCommands([
        \App\Console\Commands\GantiKataSandi::class,
        \App\Console\Commands\PeriksaProduksi::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Nama pendek yang dipakai di api/api.php:  ->middleware('jwt')  dan  ->middleware('peran:Admin')
        $middleware->alias([
            'jwt' => \App\Http\Middleware\Autentikasi::class,
            'peran' => \App\Http\Middleware\PeranDiizinkan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ApiException adalah error "yang diharapkan" (401, 404, 422, ...), tidak perlu masuk log.
        $exceptions->dontReport(ApiException::class);

        // Semua balasan error berupa JSON, termasuk untuk alamat di luar /api/v1.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Error bawaan Laravel diubah ke format kontrak: { "error": { "code", "message", "details" } }.
        // ApiException menangani dirinya sendiri lewat method render().
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof NotFoundHttpException) {
                return ApiException::balasan(404, 'TIDAK_DITEMUKAN', 'Alamat tidak ditemukan');
            }

            if ($e instanceof MethodNotAllowedHttpException) {
                $boleh = preg_split('/\s*,\s*/', (string) ($e->getHeaders()['Allow'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $boleh = array_values(array_diff($boleh, ['HEAD', 'OPTIONS']));

                return ApiException::balasan(
                    405,
                    'METODE_TIDAK_DIPERBOLEHKAN',
                    "Metode {$request->method()} tidak didukung untuk alamat ini",
                    ['diperbolehkan' => $boleh],
                );
            }

            if ($e instanceof PostTooLargeException) {
                return ApiException::balasan(413, 'BODY_TERLALU_BESAR', 'Data yang dikirim terlalu besar');
            }

            if ($e instanceof HttpExceptionInterface) {
                return ApiException::balasan($e->getStatusCode(), 'GALAT_HTTP', $e->getMessage() ?: 'Permintaan tidak dapat diproses');
            }

            // Kesalahan tak terduga: dicatat di storage/logs, detailnya tidak dibocorkan ke klien.
            report($e);

            return ApiException::balasan(500, 'SERVER_ERROR', 'Terjadi gangguan di server. Coba lagi beberapa saat lagi.');
        });
    })
    ->create();

// [MODUL 6] Kode aplikasi sekarang ada di <urusan>/ yang sejajar dengan laravel/, bukan app/. Laravel memakai app_path() untuk menebak
// namespace, jadi dipasang ke bersama/ dan dipasangkan dengan "App\\": "../bersama" di composer.json.
$app->useAppPath(dirname(__DIR__, 2).'/bersama');

// [MODUL 6] Berkas config juga dipindah ke config/ yang sejajar dengan laravel/. Tanpa baris ini Laravel mencari config/ di akar proyek.
$app->useConfigPath(dirname(__DIR__, 2).'/config');

// [MODUL 6] Migration, seeder, SQL, dan data contoh ada di database/ yang sejajar dengan laravel/. database_path() mengikuti baris ini.
$app->useDatabasePath(dirname(__DIR__, 2).'/database');

return $app;
