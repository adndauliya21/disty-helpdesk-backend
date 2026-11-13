<?php

namespace App\Enums;

/* [MODUL 6] Padanan CATEGORY di src/domain/enums.js. */
enum KategoriAduan: string
{
    case Gangguan = 'Gangguan / Error';
    case Pengembangan = 'Pengembangan / Update Fitur';

    /** @return list<string> */
    public static function nilai(): array
    {
        return array_column(self::cases(), 'value');
    }
}
