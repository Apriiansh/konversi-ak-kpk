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
        // 1. Tambah TMT pada tabel pegawai
        Schema::table('pegawai', function (Blueprint $table) {
            $table->date('tmt_jabatan')->nullable()->after('pendidikan_terakhir');
        });

        // 2. Tambah kolom triwulan dan jumlah_bulan pada evaluasi_kinerja
        Schema::table('evaluasi_kinerja', function (Blueprint $table) {
            $table->unsignedTinyInteger('triwulan')->nullable()->after('tahun'); // 1, 2, 3, 4
            $table->unsignedTinyInteger('jumlah_bulan')->default(3)->after('triwulan'); // bulan aktif dalam triwulan ini
        });

        // 3. Tambah kolom pendukung kalkulasi komprehensif pada penetapan_ak
        Schema::table('penetapan_ak', function (Blueprint $table) {
            $table->decimal('ak_pak_pelantikan', 8, 2)->default(0)->after('ak_dasar');
            $table->decimal('ak_historis', 8, 2)->default(0)->after('ak_pak_pelantikan');
            $table->decimal('ak_booster', 8, 2)->default(0)->after('ak_baru');
            $table->decimal('ak_carry_over', 8, 2)->default(0)->after('ak_booster');
            $table->string('status_kelayakan')->default('BELUM_CUKUP')->after('ak_kumulatif'); // BELUM_CUKUP, LAYAK_PANGKAT, LAYAK_JENJANG
            $table->text('catatan_kelayakan')->nullable()->after('status_kelayakan');
            $table->boolean('is_final')->default(false)->after('catatan_kelayakan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('penetapan_ak', function (Blueprint $table) {
            $table->dropColumn([
                'ak_pak_pelantikan',
                'ak_historis',
                'ak_booster',
                'ak_carry_over',
                'status_kelayakan',
                'catatan_kelayakan',
                'is_final'
            ]);
        });

        Schema::table('evaluasi_kinerja', function (Blueprint $table) {
            $table->dropColumn(['triwulan', 'jumlah_bulan']);
        });

        Schema::table('pegawai', function (Blueprint $table) {
            $table->dropColumn('tmt_jabatan');
        });
    }
};
