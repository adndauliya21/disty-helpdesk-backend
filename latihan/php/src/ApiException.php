<?php
declare(strict_types=1);

namespace Latihan;

/* Kesalahan yang diterjemahkan menjadi respons JSON dengan kode status dan kode galat. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $kode,
        string $pesan,
        public readonly ?array $rincian = null,
    ) {
        parent::__construct($pesan);
    }
}
