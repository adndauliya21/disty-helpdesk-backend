<?php

namespace Tests\Unit;

use App\Exceptions\TokenException;
use App\Support\Jwt;
use PHPUnit\Framework\TestCase;

/* [MODUL 6] Pengujian JWT buatan sendiri (porting bagian "JWT" di tests/auth.test.js Modul 5). Tidak butuh Laravel/database. */
class JwtTest extends TestCase
{
    private const RAHASIA = 'rahasia-uji';

    private function b64(string $nilai): string
    {
        return rtrim(strtr(base64_encode($nilai), '+/', '-_'), '=');
    }

    private function kodeGalat(string $token, string $rahasia = self::RAHASIA): string
    {
        try {
            Jwt::periksa($token, $rahasia);
        } catch (TokenException $e) {
            return $e->kode;
        }
        $this->fail('Seharusnya TokenException');
    }

    public function test_token_yang_diterbitkan_bisa_diperiksa_dan_payload_utuh(): void
    {
        $token = Jwt::buat(['sub' => 7, 'name' => 'Bu Rina', 'role' => 'Klien'], self::RAHASIA, 60);
        $payload = Jwt::periksa($token, self::RAHASIA);

        $this->assertSame(7, $payload['sub']);
        $this->assertSame('Bu Rina', $payload['name']);
        $this->assertSame($payload['iat'] + 60, $payload['exp']);
    }

    public function test_payload_diubah_tanda_tangan_tidak_cocok(): void
    {
        [$h, , $t] = explode('.', Jwt::buat(['role' => 'Klien'], self::RAHASIA, 60));
        $palsu = $this->b64(json_encode(['role' => 'Admin', 'exp' => time() + 999]));

        $this->assertSame('TIDAK_VALID', $this->kodeGalat("{$h}.{$palsu}.{$t}"));
    }

    public function test_rahasia_berbeda_ditolak(): void
    {
        $this->assertSame('TIDAK_VALID', $this->kodeGalat(Jwt::buat(['a' => 1], 'rahasia-lain', 60)));
    }

    public function test_kedaluwarsa(): void
    {
        $this->assertSame('KEDALUWARSA', $this->kodeGalat(Jwt::buat(['a' => 1], self::RAHASIA, -1)));
    }

    public function test_algoritma_none_ditolak(): void
    {
        $token = $this->b64('{"alg":"none","typ":"JWT"}').'.'.$this->b64('{"role":"Admin","exp":'.(time() + 999).'}').'.';

        $this->assertSame('TIDAK_VALID', $this->kodeGalat($token));
    }

    public function test_sampah_tidak_valid(): void
    {
        foreach (['', 'abc', 'a.b', 'a.b.c', '...'] as $sampah) {
            $this->assertSame('TIDAK_VALID', $this->kodeGalat($sampah));
        }
    }
}
