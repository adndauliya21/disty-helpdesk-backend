<?php

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Login dan middleware autentikasi. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_login_klien_sesuai_kontrak_dan_mencatat_last_login(): void
    {
        $balasan = $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'rahasia123'])->assertOk();

        $this->assertSame(3, count(explode('.', $balasan->json('data.token'))));
        $this->assertSame('Bearer', $balasan->json('data.tokenType'));
        $this->assertSame(
            ['role' => 'klien', 'name' => 'Bu Rina', 'email' => 'rina@smkn2.sch.id', 'instansiCode' => 'SMKN2-SBY'],
            $balasan->json('data.user'),
        );
        $this->assertStringNotContainsString('password', $balasan->getContent());
        $this->assertNotNull(DB::table('pengguna')->where('email', 'rina@smkn2.sch.id')->value('last_login'));
    }

    public function test_peran_staf_dan_email_tidak_peka_huruf_besar_kecil(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'RAFI@perusahaan.id', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('data.user.role', 'teknisi')->assertJsonPath('data.user.name', 'Rafi Pratama');
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@perusahaan.id', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('data.user.role', 'admin')->assertJsonPath('data.user.instansiCode', null);
    }

    public function test_sandi_salah_email_tidak_ada_dan_akun_nonaktif_sama_sama_401(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'salah'])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => 'tidak@ada.id', 'password' => 'rahasia123'])->assertStatus(401);

        DB::table('pengguna')->where('email', 'rina@smkn2.sch.id')->update(['status' => 'nonaktif']);
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'rahasia123'])->assertStatus(401);
    }

    public function test_endpoint_butuh_token_dan_peran_dicek(): void
    {
        $this->getJson('/api/v1/tickets')->assertStatus(401);
        $this->getJson('/api/v1/tickets', ['Authorization' => 'Bearer '.Jwt::buat(['sub' => 1, 'role' => 'Admin'], config('disty.jwt.rahasia'), -10)])->assertStatus(401);
        $this->getJson('/api/v1/auth/me', $this->masuk('rina'))->assertOk()->assertJsonPath('data.email', 'rina@smkn2.sch.id');
        $this->postJson('/api/v1/technicians', ['name' => 'X'], $this->masuk('rina'))->assertStatus(403);
    }
}
