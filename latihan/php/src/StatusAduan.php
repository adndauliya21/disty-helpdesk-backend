<?php
declare(strict_types=1);

namespace Latihan;

enum StatusAduan: string
{
    case Baru = 'Baru';
    case Diproses = 'Diproses';
    case Monitoring = 'Monitoring';
    case Selesai = 'Selesai';

    /** @return string[] */
    public static function nilai(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
