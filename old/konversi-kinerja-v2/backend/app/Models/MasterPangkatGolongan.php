<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterPangkatGolongan extends Model
{
    use HasUuids;

    protected $table = 'master_pangkat_golongan';

    protected $fillable = [
        'jenjang_id',
        'golongan',
        'ak_dasar',
    ];

    public function jenjangJabatan(): BelongsTo
    {
        return $this->belongsTo(MasterJenjangJabatan::class, 'jenjang_id');
    }

    public function pegawai(): HasMany
    {
        return $this->hasMany(Pegawai::class, 'pangkat_golongan_id');
    }
}
