<?php
declare(strict_types=1);

namespace Latihan;

/* Validasi isian aduan dengan struktur kontrol biasa (if, foreach, in_array). */
final class Validator
{
    public const KATEGORI = ['Gangguan / Error', 'Permintaan Fitur', 'Pertanyaan'];
    public const PRIORITAS = ['Urgent', 'Tinggi', 'Sedang', 'Rendah'];

    public static function aduan(array $isi): array
    {
        $galat = [];
        $judul = is_string($isi['title'] ?? null) ? trim($isi['title']) : '';
        $deskripsi = is_string($isi['description'] ?? null) ? trim($isi['description']) : '';

        if ($deskripsi === '') {
            $galat['description'] = 'Isi cerita singkat dulu';
        }
        if ($judul === '') {
            $galat['title'] = 'Judul aduan wajib diisi';
        } elseif (mb_strlen($judul) > 100) {
            $galat['title'] = 'Judul aduan maksimal 100 karakter';
        }
        if (! in_array($isi['category'] ?? null, self::KATEGORI, true)) {
            $galat['category'] = 'Kategori tidak valid';
        }
        $prioritas = $isi['priority'] ?? null;
        if ($prioritas !== null && ! in_array($prioritas, self::PRIORITAS, true)) {
            $galat['priority'] = 'Prioritas tidak valid';
        }

        if ($galat !== []) {
            throw new ApiException(422, 'VALIDASI', 'Isian belum benar', $galat);
        }

        return ['title' => $judul, 'description' => $deskripsi, 'category' => $isi['category'], 'priority' => $prioritas];
    }

    public static function status(mixed $nilai): StatusAduan
    {
        $status = is_string($nilai) ? StatusAduan::tryFrom($nilai) : null;
        if ($status === null) {
            throw new ApiException(422, 'VALIDASI', 'Status tidak valid',
                ['status' => 'Pilih salah satu: '.implode(', ', StatusAduan::nilai())]);
        }

        return $status;
    }
}
