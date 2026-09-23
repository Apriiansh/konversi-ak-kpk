<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MasterPredikatKinerja extends Model
{
    use HasUuids;

    protected $table = 'master_predikat_kinerja';

    protected $fillable = [
        'nama',
        'persentase_konversi',
    ];
}
