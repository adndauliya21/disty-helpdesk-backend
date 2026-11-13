<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/* [MODUL 6] Padanan src/support/waktu.js. Kontrak API memakai ISO 8601 UTC dengan milidetik: 2026-09-30T02:10:00.000Z. */
final class Waktu
{
    public static function iso(CarbonInterface $waktu): string
    {
        return Carbon::instance($waktu)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Waktu sekarang di WIB (zona pengguna, Surabaya). Dipakai untuk "hari ini" dan jam konsultasi yang sudah lewat. */
    public static function sekarangWib(): Carbon
    {
        return Carbon::now('Asia/Jakarta');
    }

    /** Waktu sekarang dipotong ke milidetik, sama dengan ketelitian kolom DATETIME(3). */
    public static function sekarang(): Carbon
    {
        return Carbon::createFromTimestampMs((int) floor(microtime(true) * 1000), 'UTC');
    }
}
