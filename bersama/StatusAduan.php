<?php

namespace App\Enums;

/* [MODUL 6] Enum PHP 8.1 (padanan STATUS di src/domain/enums.js). Nilai teksnya sama dengan frontend dan kolom ENUM di database. */
enum StatusAduan: string
{
    case Baru = 'Baru';
    case Diproses = 'Diproses';
    case Monitoring = 'Monitoring';
    case Selesai = 'Selesai';

    /** @return list<string> */
    public static function nilai(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Satu-satunya status tujuan yang sah dari status ini. Urutan wajib: Baru -> Diproses -> Monitoring -> Selesai (tidak boleh melompat atau mundur). */
    public function berikutnya(): ?self
    {
        return match ($this) {
            self::Baru => self::Diproses,
            self::Diproses => self::Monitoring,
            self::Monitoring => self::Selesai,
            self::Selesai => null,
        };
    }
}
