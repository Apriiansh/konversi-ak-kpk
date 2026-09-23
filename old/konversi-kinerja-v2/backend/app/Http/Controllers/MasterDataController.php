<?php

namespace App\Http\Controllers;

use App\Models\MasterJenjangJabatan;
use App\Models\MasterPangkatGolongan;
use App\Models\MasterPredikatKinerja;
use Illuminate\Http\JsonResponse;

class MasterDataController extends Controller
{
    /**
     * Menyediakan referensi master data BKN No. 3 Tahun 2023
     * untuk jenjang jabatan, predikat kinerja, dan AK dasar.
     */
    public function index(): JsonResponse
    {
        $jenjangJabatan = MasterJenjangJabatan::query()
            ->orderBy('nama')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'nama' => $item->nama,
                    'koefisien_tahunan' => (float) $item->koefisien_tahunan,
                    'kebutuhan_ak_kp' => (float) $item->kebutuhan_ak_kp,
                    'kebutuhan_ak_jenjang' => (float) $item->kebutuhan_ak_jenjang,
                ];
            });

        $predikatKinerja = MasterPredikatKinerja::query()
            ->orderBy('persentase_konversi', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'nama' => $item->nama,
                    'persentase_konversi' => (float) $item->persentase_konversi,
                    'persentase' => (float) $item->persentase_konversi,
                ];
            });

        $akDasar = MasterPangkatGolongan::query()
            ->with('jenjangJabatan:id,nama')
            ->orderBy('golongan')
            ->get()
            ->map(function ($item) {
                $jenjangNama = $item->jenjangJabatan?->nama ?? '';

                return [
                    'id' => $item->id,
                    'kunci_pencarian' => $jenjangNama !== '' ? $jenjangNama . '-' . $item->golongan : $item->golongan,
                    'jenjang_jabatan' => $jenjangNama,
                    'golongan_ruang' => $item->golongan,
                    'ak_dasar' => (float) $item->ak_dasar,
                ];
            });

        return response()->json([
            'message' => 'Master data peraturan BKN Nomor 3 Tahun 2023.',
            'data' => [
                'jenjang_jabatan' => $jenjangJabatan,
                'predikat_kinerja' => $predikatKinerja,
                'ak_dasar' => $akDasar,
            ],
        ]);
    }
}
