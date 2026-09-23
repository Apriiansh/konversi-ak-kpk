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
        // Jalur Jabatan (PerBKN No. 3/2023): asal jabatan & jenjang efektif
        // memungkinkan kalkulasi/penyimpanan mismatch golongan vs jenjang tujuan.
        Schema::table('pegawai', function (Blueprint $table) {
            $table->string('asal_jabatan')->nullable()->after('pangkat_golongan_id'); // JABATAN_FUNGSIONAL, PELAKSANA, PENGAWAS, ADMINISTRATOR, PENGANGKATAN_PERTAMA
            $table->foreignUuid('jenjang_jabatan_id')->nullable()->after('asal_jabatan')
                ->constrained('master_jenjang_jabatan')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pegawai', function (Blueprint $table) {
            $table->dropForeign(['jenjang_jabatan_id']);
            $table->dropColumn(['asal_jabatan', 'jenjang_jabatan_id']);
        });
    }
};
