<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pendidikan', function (Blueprint $table) {
            $table->string('program_studi')->after('jenjang_pendidikan');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pendidikan', function (Blueprint $table) {
            $table->dropColumn('program_studi');
        });
    }
};
