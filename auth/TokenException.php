<?php

namespace App\Exceptions;

use RuntimeException;

/* [MODUL 6] Padanan TokenError (src/auth/jwt.js). $kode: 'KEDALUWARSA' atau 'TIDAK_VALID'. */
class TokenException extends RuntimeException
{
    public function __construct(public readonly string $kode, string $pesan)
    {
        parent::__construct($pesan);
    }
}
