<?php

namespace App\Services;

use App\Models\RiwayatAktivitas;
use Illuminate\Support\Facades\Request;

class AuditTrailService
{
    /**
     * Catat aktivitas pengguna ke dalam sistem.
     *
     * @param string $modul
     * @param string $aksi
     * @param string $deskripsi
     * @param array|null $dataSebelumnya
     * @param array|null $dataBaru
     * @return RiwayatAktivitas
     */
    public function log(string $modul, string $aksi, string $deskripsi, ?array $dataSebelumnya = null, ?array $dataBaru = null): RiwayatAktivitas
    {
        return RiwayatAktivitas::create([
            'user_id' => auth()->id(),
            'modul' => $modul,
            'aksi' => $aksi,
            'deskripsi' => $deskripsi,
            'data_sebelumnya' => $dataSebelumnya,
            'data_baru' => $dataBaru,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
