# node-backend/ : back-end Node.js (Modul 3)

Folder ini sejajar dengan `laravel/` dan sudah punya `README.md` sendiri yang lengkap.

Versi Node.js dari API yang sama dengan Laravel. Database dan kontrak API sama, dan Node menyajikan folder `public/` yang sama.

| Perlu apa | Buka |
|---|---|
| Cara menjalankan dan menguji | `node-backend/README.md` |
| Titik masuk | `node-backend/src/server.js`, `src/app.js` |
| Daftar rute | `node-backend/src/routes.js` |
| Controller, repositori, middleware | `node-backend/src/controllers/`, `repositories/`, `middleware/` |
| Pengaturan | `node-backend/src/config.js`, `node-backend/.env.example` |
| Tes | `node-backend/tests/unit/`, `node-backend/tests/integrasi/` |
| Padanan di Laravel | `api/` |

Silabus: Node.js ada di Modul 3, Backend Berbasis JavaScript.

## Aturan
- Folder ini **tidak dipindah** tanpa mengubah kode. `src/config.js` membaca `.env` di dalam `node-backend/` dan menyajikan `../laravel/public` (bisa diganti lewat `PUBLIC_DIR`). `tests/helper-db.js` membaca `../../database/disty_helpdesk.sql`.
- README menyebut 73 tes. Angka itu belum pernah dibuktikan di lingkungan kerja Claude karena butuh MySQL.
