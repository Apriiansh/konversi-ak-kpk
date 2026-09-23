<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('evaluasi_kinerja', function (Blueprint $table) {
            $table->decimal('angka_kredit', 10, 3)->change();
        });

        Schema::table('penetapan_ak', function (Blueprint $table) {
            $table->decimal('ak_dasar', 10, 3)->default(0)->change();
            $table->decimal('ak_pak_pelantikan', 10, 3)->default(0)->change();
            $table->decimal('ak_historis', 10, 3)->default(0)->change();
            $table->decimal('ak_lama', 10, 3)->default(0)->change();
            $table->decimal('ak_baru', 10, 3)->default(0)->change();
            $table->decimal('ak_booster', 10, 3)->default(0)->change();
            $table->decimal('ak_carry_over', 10, 3)->default(0)->change();
            $table->decimal('ak_kumulatif', 10, 3)->default(0)->change();
        });

        Schema::table('pengajuan_pendidikan', function (Blueprint $table) {
            $table->decimal('ak_bonus', 10, 3)->default(0)->change();
        });

        Schema::table('master_jenjang_jabatan', function (Blueprint $table) {
            $table->decimal('koefisien_tahunan', 8, 3)->change();
            $table->decimal('kebutuhan_ak_kp', 10, 3)->change();
            $table->decimal('kebutuhan_ak_jenjang', 10, 3)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evaluasi_kinerja', function (Blueprint $table) {
            $table->decimal('angka_kredit', 8, 2)->change();
        });

        Schema::table('penetapan_ak', function (Blueprint $table) {
            $table->decimal('ak_dasar', 8, 2)->default(0)->change();
            $table->decimal('ak_pak_pelantikan', 8, 2)->default(0)->change();
            $table->decimal('ak_historis', 8, 2)->default(0)->change();
            $table->decimal('ak_lama', 8, 2)->default(0)->change();
            $table->decimal('ak_baru', 8, 2)->default(0)->change();
            $table->decimal('ak_booster', 8, 2)->default(0)->change();
            $table->decimal('ak_carry_over', 8, 2)->default(0)->change();
            $table->decimal('ak_kumulatif', 8, 2)->default(0)->change();
        });

        Schema::table('pengajuan_pendidikan', function (Blueprint $table) {
            $table->decimal('ak_bonus', 8, 2)->default(0)->change();
        });

        Schema::table('master_jenjang_jabatan', function (Blueprint $table) {
            $table->decimal('koefisien_tahunan', 5, 2)->change();
            $table->decimal('kebutuhan_ak_kp', 8, 2)->change();
            $table->decimal('kebutuhan_ak_jenjang', 8, 2)->change();
        });
    }
};
