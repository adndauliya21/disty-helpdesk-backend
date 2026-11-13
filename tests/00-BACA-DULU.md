# tests/ : tes otomatis back-end Laravel

Dijalankan dari dalam `laravel/` dengan `php vendor/phpunit/phpunit/phpunit` (atau `php artisan test` bila paket collision terpasang).
Namespace `Tests\` dipetakan ke folder ini di `laravel/composer.json`, dan `laravel/phpunit.xml` menunjuk ke `../tests/Unit` dan `../tests/Feature`. Tes memakai SQLite di memori (diatur di `phpunit.xml`), jadi tidak butuh MySQL.

| Berkas | Jumlah tes | Yang diuji (dari nama berkas) |
|---|---|---|
| `Feature/ApiTest.php` | 15 | Format balasan umum, 404, 405, 422, aturan akses |
| `Feature/AuthTest.php` | 4 | Login dan token |
| `Feature/AduanTest.php` | 7 | Membuat dan melihat aduan |
| `Feature/AlurAduanTest.php` | 16 | Urutan status aduan |
| `Feature/AduanTeleponTest.php` | 13 | Aduan lewat telepon dan verifikasi Admin |
| `Feature/KonsultasiTest.php` | 9 | Konsultasi |
| `Feature/KodeInstansiTest.php` | 11 | Kode instansi |
| `Feature/ProduksiTest.php` | 7 | Pemeriksaan kesiapan produksi |
| `Feature/ProfilDanAktivasiTest.php` | 7 | Profil dan aktivasi akun |
| `Feature/ReferensiTest.php` | 13 | Data acuan |
| `Feature/ReferensiSurveiTeknisiTest.php` | 5 | Acuan, survei, teknisi |
| `Feature/SkemaErdTest.php` | 8 | Kesesuaian skema database dengan ERD |
| `Feature/SurveiTest.php` | 19 | Survei |
| `Feature/TechnicianTest.php` | 11 | Teknisi |
| `Feature/UnggahFotoDanSandiTest.php` | 10 | Unggah foto dan kata sandi |
| `Unit/JwtTest.php` | 6 | Pembuatan dan pembacaan token JWT |
| `TestCase.php` | | Kelas dasar tes |

## Hasil terakhir terukur
161 tes, 1175 asersi, 159 lulus, 2 gagal (PHP 8.3.6, pemeriksaan platform dimatikan di salinan uji). Dua kegagalan itu sudah ada sebelum perapian. Rinciannya di CLAUDE.md bagian 6. `Pest.php` sudah dihapus (Pest tidak terpasang).

## Aturan
- Jangan melemahkan atau menghapus tes agar hasil terlihat hijau.
- Kolom jumlah tes dihitung dari fungsi `test_` di tiap berkas. Isi tiap tes belum dibaca satu per satu.
