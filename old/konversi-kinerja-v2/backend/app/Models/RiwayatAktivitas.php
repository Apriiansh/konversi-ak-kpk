<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiwayatAktivitas extends Model
{
    use HasUuids;

    protected $table = 'riwayat_aktivitas';

    protected $fillable = [
        'user_id',
        'modul',
        'aksi',
        'deskripsi',
        'data_sebelumnya',
        'data_baru',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_sebelumnya' => 'array',
            'data_baru' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
