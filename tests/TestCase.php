<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Header Authorization untuk akun seed (kata sandi semuanya rahasia123).
     *  Nama akun seed (bagian email sebelum @, mis. rina) diterjemahkan ke emailnya lewat database. */
    protected function masuk(string $akun): array
    {
        $email = User::where('email', 'like', $akun.'@%')->value('email');
        $balasan = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'rahasia123']);

        return ['Authorization' => 'Bearer '.$balasan->json('data.token')];
    }
}
