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
        Schema::create('pengajuan_pendidikan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pegawai_id')->constrained('pegawai')->cascadeOnDelete();
            $table->enum('jenjang_pendidikan', ['D3', 'S1', 'S2', 'S3']);
            $table->string('jurusan');
            $table->string('nama_institusi');
            $table->integer('tahun_lulus');
            $table->string('file_ijazah'); // Path dokumen ijazah / SKL
            $table->string('file_bukti_bkn'); // Path bukti pengajuan / persetujuan BKN
            $table->enum('status', ['DIAJUKAN', 'DITOLAK_ADMIN', 'DITOLAK_SYARAT', 'DISETUJUI'])->default('DIAJUKAN');
            $table->text('catatan_verifikasi')->nullable();
            $table->decimal('ak_bonus', 8, 2)->default(0);
            $table->foreignUuid('diverifikasi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diverifikasi_pada')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengajuan_pendidikan');
    }
};
