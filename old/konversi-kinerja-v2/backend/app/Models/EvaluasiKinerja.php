<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluasiKinerja extends Model
{
    use HasUuids;

    protected $table = 'evaluasi_kinerja';

    protected $fillable = [
        'pegawai_id',
        'atasan_penilai_id',
        'tahun',
        'triwulan',
        'periode_bulan',
        'jumlah_bulan',
        'predikat_id',
        'angka_kredit',
    ];

    protected function casts(): array
    {
        return [
            'triwulan' => 'integer',
            'periode_bulan' => 'integer',
            'jumlah_bulan' => 'integer',
            'angka_kredit' => 'decimal:3',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function atasanPenilai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'atasan_penilai_id');
    }

    public function predikat(): BelongsTo
    {
        return $this->belongsTo(MasterPredikatKinerja::class, 'predikat_id');
    }
}
