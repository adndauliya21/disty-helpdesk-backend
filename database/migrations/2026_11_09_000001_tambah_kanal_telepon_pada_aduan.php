<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Aduan lewat telepon: klien menelepon teknisi langsung (nomor teknisi ada di beranda), teknisi mencatat aduannya sendiri.
     aduan.kanal                'web' (klien membuat sendiri, ditugaskan admin) | 'telepon' (dicatat teknisi, tanpa penugasan admin)
     aduan.menunggu_verifikasi  true setelah teknisi mengirim laporan pengerjaan aduan telepon, sampai admin menyetujui atau mengembalikannya.
   Padanan database/disty_helpdesk.sql (MySQL langsung). Aduan lama otomatis 'web' dan tidak menunggu verifikasi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aduan', function (Blueprint $t) {
            $t->enum('kanal', ['web', 'telepon'])->default('web')->after('status');
            $t->boolean('menunggu_verifikasi')->default(false)->after('kanal');
        });
    }

    public function down(): void
    {
        Schema::table('aduan', function (Blueprint $t) {
            $t->dropColumn(['kanal', 'menunggu_verifikasi']);
        });
    }
};
