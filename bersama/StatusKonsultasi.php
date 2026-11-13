<?php

namespace App\Enums;

/* [MODUL 8] Status konsultasi. Nilai teksnya sama dengan frontend (c.st) dan kolom ENUM di database.
   Klien selalu membuat konsultasi berstatus Menunggu; Admin hanya boleh mengubahnya ke empat status lain (lihat dariAdmin). */
enum StatusKonsultasi: string
{
    case Menunggu = 'Menunggu';
    case Terjadwal = 'Terjadwal';
    case Ditolak = 'Ditolak';
    case Jadi = 'Jadi';
    case Tidak = 'Tidak';

    /** Status yang boleh dipilih Admin lewat PATCH /consultations/{id}. @return list<string> */
    public static function dariAdmin(): array
    {
        return ['Terjadwal', 'Ditolak', 'Jadi', 'Tidak'];
    }

    /** Status yang tidak lagi memakai kuota jam: konsultasi ditolak atau batal. @return list<string> */
    public static function bebasKuota(): array
    {
        return ['Ditolak', 'Tidak'];
    }
}
