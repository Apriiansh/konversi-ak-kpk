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
        // 1. Master Jenjang Jabatan
        Schema::create('master_jenjang_jabatan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nama'); // Ahli Pertama, Ahli Muda, dsb
            $table->decimal('koefisien_tahunan', 5, 2);
            $table->decimal('kebutuhan_ak_kp', 8, 2);
            $table->decimal('kebutuhan_ak_jenjang', 8, 2);
            $table->timestamps();
        });

        // 2. Master Pangkat Golongan
        Schema::create('master_pangkat_golongan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('jenjang_id')->constrained('master_jenjang_jabatan')->cascadeOnDelete();
            $table->string('golongan'); // III/a, III/b, dsb
            $table->decimal('ak_dasar', 8, 2)->default(0);
            $table->timestamps();
        });

        // 3. Master Predikat Kinerja
        Schema::create('master_predikat_kinerja', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nama'); // Sangat Baik, Baik, dsb
            $table->decimal('persentase_konversi', 5, 2); // 1.50, 1.00, 0.75, 0.50, 0.25
            $table->timestamps();
        });

        // 4. Pegawai
        Schema::create('pegawai', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('nip')->unique();
            $table->string('nama_lengkap');
            $table->uuid('atasan_id')->nullable(); // Foreign key didefinisikan terpisah
            $table->foreignUuid('pangkat_golongan_id')->constrained('master_pangkat_golongan');
            $table->timestamps();
        });

        Schema::table('pegawai', function (Blueprint $table) {
            $table->foreign('atasan_id')->references('id')->on('pegawai')->nullOnDelete();
        });

        // 5. Evaluasi Kinerja (Transaksi)
        Schema::create('evaluasi_kinerja', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pegawai_id')->constrained('pegawai')->cascadeOnDelete();
            $table->foreignUuid('atasan_penilai_id')->nullable()->constrained('pegawai')->nullOnDelete();
            $table->integer('tahun');
            $table->integer('periode_bulan'); // 1-12
            $table->foreignUuid('predikat_id')->constrained('master_predikat_kinerja');
            $table->decimal('angka_kredit', 8, 2);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        // 6. Penetapan AK
        Schema::create('penetapan_ak', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pegawai_id')->constrained('pegawai')->cascadeOnDelete();
            $table->integer('tahun');
            $table->decimal('ak_dasar', 8, 2)->default(0);
            $table->decimal('ak_lama', 8, 2)->default(0);
            $table->decimal('ak_baru', 8, 2)->default(0);
            $table->decimal('ak_kumulatif', 8, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penetapan_ak');
        Schema::dropIfExists('evaluasi_kinerja');
        Schema::dropIfExists('pegawai');
        Schema::dropIfExists('master_predikat_kinerja');
        Schema::dropIfExists('master_pangkat_golongan');
        Schema::dropIfExists('master_jenjang_jabatan');
    }
};
