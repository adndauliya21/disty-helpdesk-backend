# Latihan Modul 2 — Bahasa Pemrograman Back-End (PHP)

Back-end aduan Disty HelpDesk dalam PHP 8.2+ murni, tanpa framework dan tanpa Composer.

| Berkas | Konsep yang dilatih |
|---|---|
| `public/index.php` | Front controller, autoload, header CORS |
| `src/Request.php`, `src/Response.php` | Mekanisme request-response: metode, alamat, query, isi JSON, kode status |
| `src/Aplikasi.php` | Routing dengan `match` dan struktur kontrol |
| `src/Validator.php` | Struktur kontrol (`if`, `in_array`), validasi isian |
| `src/TokoAduan.php` | Kelas dan enkapsulasi, penyimpanan pada berkas JSON |
| `src/StatusAduan.php` | Enum PHP |
| `src/ApiException.php` | Pewarisan kelas `RuntimeException`, penanganan galat seragam |
| `uji.php` | Pengujian tanpa PHPUnit |

```bash
php -S localhost:8000 public/index.php   # lalu buka http://localhost:8000/api/v1/ping
php uji.php
```
