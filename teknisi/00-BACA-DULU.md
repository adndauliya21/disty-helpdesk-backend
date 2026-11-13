# teknisi/ : urusan peran Teknisi

| Berkas | Fungsi |
|---|---|
| `AlurAduanController.php` | Alur aduan: ubah status, tugaskan, catat tindakan, selesaikan, verifikasi, nilai, foto |

Rute Teknisi: catat tindakan, selesaikan, foto aduan, dan buat aduan telepon (`POST /tickets/phone`, ada di `TicketController`). Rute Admin di controller yang sama: tugaskan dan verifikasi. Rute Klien: beri nilai. Controller ini ditaruh di sini karena rute Teknisi paling banyak.

Aturan yang dijaga tes: status aduan berurutan (Baru, Diproses, Monitoring, Selesai), melompat atau mundur ditolak 409. Aduan telepon butuh verifikasi Admin sebelum Selesai.

Halaman Teknisi di front-end: `laravel/public/helpdesk/technician/halaman-teknisi.js` (tidak dipindah).
