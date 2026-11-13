<?php
declare(strict_types=1);

// Front controller. Jalankan: php -S localhost:8000 -t public public/index.php
spl_autoload_register(function (string $kelas): void {
    $berkas = __DIR__.'/../src/'.str_replace('Latihan\\', '', $kelas).'.php';
    if (is_file($berkas)) {
        require $berkas;
    }
});

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,PATCH,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Expose-Headers: Location');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$aplikasi = new Latihan\Aplikasi(new Latihan\TokoAduan(__DIR__.'/../data/aduan.json'));
$aplikasi->tangani(Latihan\Request::dariServer())->kirim();
