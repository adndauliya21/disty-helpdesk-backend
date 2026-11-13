<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* Tabel instansi (ERD): kode_instansi, nama_instansi, domain_email.
   Instansi seorang klien dicari dari domain email-nya (bagian setelah @). Di MySQL, trigger di database melakukan hal yang sama;
   User::booted() mengerjakannya di PHP supaya galatnya rapi (422) dan jalan juga di SQLite saat pengujian. */
class Instansi extends Model
{
    protected $table = 'instansi';

    protected $fillable = ['kode_instansi', 'nama_instansi', 'domain_email'];

    public function klien(): HasMany
    {
        return $this->hasMany(User::class, 'instansi_id');
    }

    /** Domain dari sebuah email, huruf kecil: "Rina@SMKN2.sch.id" menjadi "smkn2.sch.id". Email tanpa @ -> teks kosong. */
    public static function domainDari(string $email): string
    {
        $email = mb_strtolower(trim($email));

        return str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : '';
    }

    /** Instansi yang cocok dengan domain email, atau null bila domainnya belum terdaftar. */
    public static function dariEmail(string $email): ?self
    {
        $domain = self::domainDari($email);

        return $domain === '' ? null : self::where('domain_email', $domain)->first();
    }

    /** Kode instansi yang cocok dengan domain email, atau null. */
    public static function kodeDariEmail(string $email): ?string
    {
        return self::dariEmail($email)?->kode_instansi;
    }
}
