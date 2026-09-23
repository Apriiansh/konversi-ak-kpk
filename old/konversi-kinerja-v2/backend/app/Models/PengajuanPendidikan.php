<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengajuanPendidikan extends Model
{
    use HasUuids;

    protected $table = 'pengajuan_pendidikan';

    protected $fillable = [
        'pegawai_id',
        'jenjang_pendidikan',
        'program_studi',
        'jurusan',
        'nama_institusi',
        'tahun_lulus',
        'file_ijazah',
        'file_bukti_bkn',
        'status',
        'catatan_verifikasi',
        'ak_bonus',
        'diverifikasi_oleh',
        'diverifikasi_pada',
    ];

    protected function casts(): array
    {
        return [
            'ak_bonus' => 'decimal:3',
            'diverifikasi_pada' => 'datetime',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }
}
