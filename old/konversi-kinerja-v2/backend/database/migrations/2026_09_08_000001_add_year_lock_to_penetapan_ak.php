<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom year-lock pada penetapan_ak
        Schema::table('penetapan_ak', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('is_final');
            $table->foreignUuid('locked_by')->nullable()->after('is_locked')->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by');
        });

        // Hapus kolom per-baris is_locked dari evaluasi_kinerja
        Schema::table('evaluasi_kinerja', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }

    public function down(): void
    {
        Schema::table('evaluasi_kinerja', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false);
        });

        Schema::table('penetapan_ak', function (Blueprint $table) {
            $table->dropColumn(['is_locked', 'locked_by', 'locked_at']);
        });
    }
};
