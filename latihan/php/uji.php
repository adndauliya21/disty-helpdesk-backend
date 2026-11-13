<?php
declare(strict_types=1);

// Pengujian tanpa PHPUnit. Jalankan: php uji.php
spl_autoload_register(fn ($k) => is_file($f = __DIR__.'/src/'.str_replace('Latihan\\', '', $k).'.php') ? require $f : null);

use Latihan\{Aplikasi, Request, TokoAduan};

$berkas = sys_get_temp_dir().'/aduan-uji-'.getmypid().'.json';
$app = new Aplikasi(new TokoAduan($berkas));
$gagal = 0; $total = 0;

function periksa(string $nama, bool $kondisi): void
{
    global $gagal, $total;
    $total++;
    echo ($kondisi ? '  lulus  ' : '  GAGAL  ').$nama."\n";
    if (! $kondisi) { $gagal++; }
}
function panggil(Aplikasi $a, string $m, string $j, ?array $isi = null, array $q = []): array
{
    $r = $a->tangani(new Request($m, $j, $q, $isi === null ? '' : json_encode($isi)));

    return [$r->status, $r->badan, $r->header];
}

[$s, $b] = panggil($app, 'GET', '/api/v1/ping');
periksa('ping 200', $s === 200 && $b['data']['status'] === 'ok');

[$s, $b, $h] = panggil($app, 'POST', '/api/v1/tickets', ['title' => 'Printer macet', 'description' => 'Tidak mencetak', 'category' => 'Gangguan / Error']);
periksa('buat aduan 201 + Location + HD-001', $s === 201 && $h['Location'] === '/api/v1/tickets/HD-001' && $b['data']['status'] === 'Baru');

[$s, $b] = panggil($app, 'POST', '/api/v1/tickets', ['description' => 'x', 'category' => 'Pertanyaan']);
periksa('tanpa judul 422', $s === 422 && isset($b['error']['details']['title']));

[$s] = panggil($app, 'PATCH', '/api/v1/tickets/HD-001', ['status' => 'Ngawur']);
periksa('status tidak valid 422', $s === 422);

[$s, $b] = panggil($app, 'PATCH', '/api/v1/tickets/HD-001', ['status' => 'Diproses']);
periksa('ubah status', $s === 200 && $b['data']['status'] === 'Diproses');

[$s, $b] = panggil($app, 'GET', '/api/v1/tickets', null, ['status' => 'Diproses']);
periksa('filter status', $s === 200 && count($b['data']) === 1);

[$s] = panggil($app, 'DELETE', '/api/v1/tickets/HD-001');
periksa('hapus 204', $s === 204);
[$s] = panggil($app, 'GET', '/api/v1/tickets/HD-001');
periksa('setelah dihapus 404', $s === 404);
[$s] = panggil($app, 'DELETE', '/api/v1/tickets');
periksa('metode salah 405', $s === 405);
[$s] = panggil($app, 'GET', '/tidak-ada');
periksa('alamat asing 404', $s === 404);
$r = $app->tangani(new Request('POST', '/api/v1/tickets', [], '{rusak'));
periksa('JSON rusak 400', $r->status === 400);

@unlink($berkas);
echo "\n$total pengujian, $gagal gagal\n";
exit($gagal === 0 ? 0 : 1);
