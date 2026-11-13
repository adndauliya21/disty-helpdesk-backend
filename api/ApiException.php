<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/* [MODUL 6] Padanan HttpError (src/core/http-error.js). Dilempar dari mana saja (controller, middleware),
   lalu Laravel memanggil render() untuk mengubahnya jadi JSON sesuai docs/API-KONTRAK:
   { "error": { "code": "KODE", "message": "...", "details": ... } } */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $kode,
        string $pesan,
        public readonly mixed $rincian = null,
    ) {
        parent::__construct($pesan);
    }

    public function render(): JsonResponse
    {
        return self::balasan($this->status, $this->kode, $this->getMessage(), $this->rincian);
    }

    public static function balasan(int $status, string $kode, string $pesan, mixed $rincian = null): JsonResponse
    {
        $error = ['code' => $kode, 'message' => $pesan];
        if ($rincian !== null) {
            $error['details'] = $rincian;
        }

        return response()->json(['error' => $error], $status);
    }
}
