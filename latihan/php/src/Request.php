<?php
declare(strict_types=1);

namespace Latihan;

/* Mewakili satu permintaan HTTP: metode, alamat, query, dan isi JSON. */
final class Request
{
    public function __construct(
        public readonly string $metode,
        public readonly string $jalur,
        public readonly array $query = [],
        private readonly string $isiMentah = '',
    ) {}

    public static function dariServer(): self
    {
        $jalur = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $jalur, $_GET, file_get_contents('php://input') ?: '');
    }

    public function isi(): array
    {
        if ($this->isiMentah === '') {
            return [];
        }
        $hasil = json_decode($this->isiMentah, true);
        if (! is_array($hasil)) {
            throw new ApiException(400, 'JSON_RUSAK', 'Isi permintaan bukan JSON yang valid');
        }

        return $hasil;
    }
}
