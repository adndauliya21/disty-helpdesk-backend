<?php

namespace App\Support;

use App\Models\KuotaKonsultasi as BarisKuota;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/* KUOTA JADWAL KONSULTASI, disimpan di tabel kuota_konsultasi (ERD): satu baris per jam.
     tanggal NULL  = kuota bawaan jam itu (berlaku semua hari kerja)    -> defaults
     tanggal terisi = kuota khusus untuk tanggal dan jam itu            -> overrides
   Kuota sebuah jam pada sebuah tanggal = kuota khusus bila ada, kalau tidak kuota bawaan jam itu, kalau tidak KUOTA_AWAL.
   Di database jam ditulis "10:00", di API dan frontend "10.00". Bentuk JSON (sama dengan QD dan QO di frontend):
   { defaults: { "09.00": 2, ... }, overrides: { "2026-10-08|10.00": 3 } } */
final class KuotaKonsultasi
{
    /** Jam konsultasi tetap, sama dengan SLOTS di frontend. */
    public const JAM = ['09.00', '10.00', '11.00', '13.00', '14.00', '15.00'];

    /** Kuota bawaan bila sebuah jam belum diatur (QD di frontend). */
    public const KUOTA_AWAL = 2;

    public const MAKS = 20;

    private static function keApi(string $jam): string
    {
        return str_replace(':', '.', substr($jam, 0, 5));
    }

    private static function keDb(string $jam): string
    {
        return str_replace('.', ':', $jam);
    }

    /** Baca tabel: [defaults, overrides] sebagai array biasa. */
    private static function baca(): array
    {
        $defaults = array_fill_keys(self::JAM, self::KUOTA_AWAL);
        $overrides = [];

        foreach (BarisKuota::orderBy('id')->get() as $baris) {
            $jam = self::keApi((string) $baris->jam);
            if (! in_array($jam, self::JAM, true)) {
                continue;
            }
            if ($baris->tanggal === null) {
                $defaults[$jam] = (int) $baris->kuota;
            } else {
                $overrides[substr((string) $baris->tanggal, 0, 10).'|'.$jam] = (int) $baris->kuota;
            }
        }

        return [$defaults, $overrides];
    }

    /** Kuota jam tertentu pada tanggal tertentu, atau null bila jam itu bukan salah satu JAM. */
    public static function kuotaJam(string $jam, string $tanggal): ?int
    {
        if (! in_array($jam, self::JAM, true)) {
            return null;
        }
        [$defaults, $overrides] = self::baca();

        return (int) ($overrides[$tanggal.'|'.$jam] ?? $defaults[$jam]);
    }

    /** Seluruh kuota dalam bentuk JSON kontrak. `overrides` kosong tetap berupa objek {} (bukan []). */
    public static function semua(): array
    {
        [$defaults, $overrides] = self::baca();
        ksort($overrides);

        return ['defaults' => $defaults, 'overrides' => (object) array_map('intval', $overrides)];
    }

    /** Ganti seluruh kuota (bukan digabung): yang tidak ada di $overrides dihapus. Data sudah divalidasi pemanggil.
     *  $idAdmin = admin yang mengatur (kolom diatur_oleh). */
    public static function simpan(array $defaults, array $overrides, int $idAdmin): void
    {
        DB::transaction(function () use ($defaults, $overrides, $idAdmin) {
            BarisKuota::query()->delete();
            foreach (self::JAM as $jam) {
                BarisKuota::create(['diatur_oleh' => $idAdmin, 'tanggal' => null, 'jam' => self::keDb($jam), 'kuota' => (int) $defaults[$jam]]);
            }
            foreach ($overrides as $kunci => $kuota) {
                [$tanggal, $jam] = explode('|', (string) $kunci);
                BarisKuota::create(['diatur_oleh' => $idAdmin, 'tanggal' => $tanggal, 'jam' => self::keDb($jam), 'kuota' => (int) $kuota]);
            }
        });
    }

    /** Bilangan bulat 0 sampai 20. JSON 3.0 (float tanpa pecahan) dianggap bulat, sama dengan Number.isInteger di JavaScript. */
    public static function kuotaValid(mixed $nilai): bool
    {
        if (is_float($nilai) && is_finite($nilai) && floor($nilai) === $nilai) {
            $nilai = (int) $nilai;
        }

        return is_int($nilai) && $nilai >= 0 && $nilai <= self::MAKS;
    }

    /** Kunci kuota khusus harus "TTTT-BB-HH|JJ.MM" dengan tanggal yang ada di kalender dan jam salah satu JAM. */
    public static function kunciValid(mixed $kunci): bool
    {
        $kunci = (string) $kunci;
        if (substr_count($kunci, '|') !== 1) {
            return false;
        }
        [$tanggal, $jam] = explode('|', $kunci);

        return in_array($jam, self::JAM, true)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) === 1
            && Carbon::createFromFormat('!Y-m-d', $tanggal)?->format('Y-m-d') === $tanggal;
    }
}
