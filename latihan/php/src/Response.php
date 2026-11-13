<?php
declare(strict_types=1);

namespace Latihan;

/* Respons JSON: { "data": ... } bila berhasil, { "error": {...} } bila gagal. */
final class Response
{
    public function __construct(public readonly int $status, public readonly ?array $badan, public readonly array $header = []) {}

    public static function data(mixed $data, int $status = 200, array $header = []): self
    {
        return new self($status, ['data' => $data], $header);
    }

    public static function kosong(): self
    {
        return new self(204, null);
    }

    public static function galat(ApiException $e): self
    {
        $error = ['code' => $e->kode, 'message' => $e->getMessage()];
        if ($e->rincian !== null) {
            $error['details'] = $e->rincian;
        }

        return new self($e->status, ['error' => $error]);
    }

    public function kirim(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->header as $nama => $nilai) {
            header("$nama: $nilai");
        }
        if ($this->badan !== null) {
            echo json_encode($this->badan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}
