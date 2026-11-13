<?php
declare(strict_types=1);

namespace Latihan;

/* Pengarah permintaan -> penangan (routing sederhana), lalu membentuk Response. */
final class Aplikasi
{
    private const AWALAN = '/api/v1';

    public function __construct(private readonly TokoAduan $toko) {}

    public function tangani(Request $req): Response
    {
        try {
            if (! str_starts_with($req->jalur, self::AWALAN)) {
                throw new ApiException(404, 'TIDAK_DITEMUKAN', 'Alamat tidak ditemukan');
            }

            return $this->arahkan($req, substr($req->jalur, strlen(self::AWALAN)) ?: '/');
        } catch (ApiException $e) {
            return Response::galat($e);
        } catch (\Throwable) {
            return Response::galat(new ApiException(500, 'SERVER_ERROR', 'Terjadi gangguan pada server'));
        }
    }

    private function arahkan(Request $req, string $jalur): Response
    {
        $m = $req->metode;
        if ($jalur === '/ping') {
            return $m === 'GET' ? Response::data(['status' => 'ok', 'runtime' => 'php', 'versi' => PHP_VERSION]) : $this->salah();
        }
        if ($jalur === '/tickets') {
            return match ($m) {
                'GET' => Response::data($this->toko->daftar($req->query['status'] ?? null)),
                'POST' => $this->buat($req),
                default => $this->salah(),
            };
        }
        if (preg_match('#^/tickets/([A-Za-z0-9-]+)$#', $jalur, $cocok)) {
            $id = $cocok[1];

            return match ($m) {
                'GET' => Response::data($this->toko->ambil($id)),
                'PATCH' => Response::data($this->toko->ubahStatus($id, Validator::status($req->isi()['status'] ?? null))),
                'DELETE' => $this->hapus($id),
                default => $this->salah(),
            };
        }
        throw new ApiException(404, 'TIDAK_DITEMUKAN', 'Alamat tidak ditemukan');
    }

    private function buat(Request $req): Response
    {
        $aduan = $this->toko->buat(Validator::aduan($req->isi()));

        return Response::data($aduan, 201, ['Location' => self::AWALAN.'/tickets/'.$aduan['id']]);
    }

    private function hapus(string $id): Response
    {
        $this->toko->hapus($id);

        return Response::kosong();
    }

    private function salah(): Response
    {
        throw new ApiException(405, 'METODE_SALAH', 'Metode tidak diizinkan untuk alamat ini');
    }
}
