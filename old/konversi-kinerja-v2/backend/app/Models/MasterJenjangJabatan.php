<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterJenjangJabatan extends Model
{
    use HasUuids;

    protected $table = 'master_jenjang_jabatan';

    protected $fillable = [
        'nama',
        'koefisien_tahunan',
        'kebutuhan_ak_kp',
        'kebutuhan_ak_jenjang',
    ];

    public function pangkatGolongan(): HasMany
    {
        return $this->hasMany(MasterPangkatGolongan::class, 'jenjang_id');
    }
}
