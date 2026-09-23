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
        Schema::create('riwayat_aktivitas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('modul'); // e.g., 'EVALUASI_KINERJA', 'MASTER_DATA'
            $table->string('aksi'); // e.g., 'CREATE', 'UPDATE', 'LOCK', 'DELETE'
            $table->text('deskripsi'); // e.g., 'Atasan Budi mengunci evaluasi kinerja untuk pegawai Andi'
            $table->json('data_sebelumnya')->nullable();
            $table->json('data_baru')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat_aktivitas');
    }
};
