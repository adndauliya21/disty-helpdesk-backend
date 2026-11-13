<?php

namespace App\Enums;

/* [MODUL 6] Padanan PRIORITY di src/domain/enums.js. */
enum Prioritas: string
{
    case Urgent = 'Urgent';
    case Tinggi = 'Tinggi';
    case Sedang = 'Sedang';
    case Rendah = 'Rendah';

    /** @return list<string> */
    public static function nilai(): array
    {
        return array_column(self::cases(), 'value');
    }
}
