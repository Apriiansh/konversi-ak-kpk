<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenetapanAK extends Model
{
    use HasUuids;

    protected $table = 'penetapan_ak';

    protected $fillable = [
        'pegawai_id',
        'tahun',
        'ak_dasar',
        'ak_pak_pelantikan',
        'ak_historis',
        'ak_lama',
        'ak_baru',
        'ak_booster',
        'ak_carry_over',
        'ak_kumulatif',
        'status_kelayakan',
        'catatan_kelayakan',
        'is_final',
        'is_locked',
        'locked_by',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'ak_dasar' => 'decimal:3',
            'ak_pak_pelantikan' => 'decimal:3',
            'ak_historis' => 'decimal:3',
            'ak_lama' => 'decimal:3',
            'ak_baru' => 'decimal:3',
            'ak_booster' => 'decimal:3',
            'ak_carry_over' => 'decimal:3',
            'ak_kumulatif' => 'decimal:3',
            'is_final' => 'boolean',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'locked_by');
    }
}
