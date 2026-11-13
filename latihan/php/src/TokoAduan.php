<?php
declare(strict_types=1);

namespace Latihan;

/* Penyimpanan aduan pada berkas JSON (tanpa basis data) agar fokus pada dasar PHP. */
final class TokoAduan
{
    public function __construct(private readonly string $berkas) {}

    private function baca(): array
    {
        if (! is_file($this->berkas)) {
            return [];
        }

        return json_decode((string) file_get_contents($this->berkas), true) ?: [];
    }

    private function tulis(array $data): void
    {
        file_put_contents($this->berkas, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function daftar(?string $status = null): array
    {
        $semua = $this->baca();

        return array_values($status === null ? $semua : array_filter($semua, fn ($a) => $a['status'] === $status));
    }

    public function ambil(string $id): array
    {
        foreach ($this->baca() as $aduan) {
            if ($aduan['id'] === $id) {
                return $aduan;
            }
        }
        throw new ApiException(404, 'TIDAK_DITEMUKAN', 'Aduan tidak ditemukan');
    }

    public function buat(array $data): array
    {
        $semua = $this->baca();
        $terbesar = 0;
        foreach ($semua as $a) {
            $terbesar = max($terbesar, (int) substr($a['id'], 3));
        }
        $aduan = ['id' => sprintf('HD-%03d', $terbesar + 1), ...$data, 'status' => StatusAduan::Baru->value, 'createdAt' => gmdate('c')];
        $semua[] = $aduan;
        $this->tulis($semua);

        return $aduan;
    }

    public function ubahStatus(string $id, StatusAduan $status): array
    {
        $semua = $this->baca();
        foreach ($semua as $i => $a) {
            if ($a['id'] === $id) {
                $semua[$i]['status'] = $status->value;
                $this->tulis($semua);

                return $semua[$i];
            }
        }
        throw new ApiException(404, 'TIDAK_DITEMUKAN', 'Aduan tidak ditemukan');
    }

    public function hapus(string $id): void
    {
        $semua = $this->baca();
        $sisa = array_filter($semua, fn ($a) => $a['id'] !== $id);
        if (count($sisa) === count($semua)) {
            throw new ApiException(404, 'TIDAK_DITEMUKAN', 'Aduan tidak ditemukan');
        }
        $this->tulis($sisa);
    }
}
