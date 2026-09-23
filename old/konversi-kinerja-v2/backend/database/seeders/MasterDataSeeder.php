<?php

namespace Database\Seeders;

use App\Models\MasterJenjangJabatan;
use App\Models\MasterPangkatGolongan;
use App\Models\MasterPredikatKinerja;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    /**
     * Run the database seeds according to PerBKN No. 3 / 2023.
     */
    public function run(): void
    {
        // 1. MASTER JENJANG JABATAN
        $jenjangData = [
            'Ahli Pertama' => [
                'koefisien_tahunan'    => 12.5,
                'kebutuhan_ak_kp'      => 50.0,
                'kebutuhan_ak_jenjang' => 100.0,
            ],
            'Ahli Muda' => [
                'koefisien_tahunan'    => 25.0,
                'kebutuhan_ak_kp'      => 100.0,
                'kebutuhan_ak_jenjang' => 200.0,
            ],
            'Ahli Madya' => [
                'koefisien_tahunan'    => 37.5,
                'kebutuhan_ak_kp'      => 150.0,
                'kebutuhan_ak_jenjang' => 450.0,
            ],
            'Ahli Utama' => [
                'koefisien_tahunan'    => 50.0,
                'kebutuhan_ak_kp'      => 200.0,
                'kebutuhan_ak_jenjang' => 9999.0,
            ],
        ];

        $jenjangModels = [];
        foreach ($jenjangData as $nama => $attrs) {
            $jenjangModels[$nama] = MasterJenjangJabatan::firstOrCreate(
                ['nama' => $nama],
                $attrs
            );
        }

        // 2. MASTER PANGKAT GOLONGAN & AK DASAR
        $pangkatData = [
            ['jenjang' => 'Ahli Pertama', 'golongan' => 'III/a', 'ak_dasar' => 0],
            ['jenjang' => 'Ahli Pertama', 'golongan' => 'III/b', 'ak_dasar' => 50],
            ['jenjang' => 'Ahli Muda',    'golongan' => 'III/c', 'ak_dasar' => 0],
            ['jenjang' => 'Ahli Muda',    'golongan' => 'III/d', 'ak_dasar' => 100],
            ['jenjang' => 'Ahli Madya',   'golongan' => 'IV/a',  'ak_dasar' => 0],
            ['jenjang' => 'Ahli Madya',   'golongan' => 'IV/b',  'ak_dasar' => 150],
            ['jenjang' => 'Ahli Madya',   'golongan' => 'IV/c',  'ak_dasar' => 300],
            ['jenjang' => 'Ahli Utama',   'golongan' => 'IV/d',  'ak_dasar' => 0],
            ['jenjang' => 'Ahli Utama',   'golongan' => 'IV/e',  'ak_dasar' => 0],
        ];

        foreach ($pangkatData as $item) {
            $jenjang = $jenjangModels[$item['jenjang']] ?? null;
            if ($jenjang) {
                MasterPangkatGolongan::firstOrCreate(
                    [
                        'jenjang_id' => $jenjang->id,
                        'golongan'   => $item['golongan'],
                    ],
                    [
                        'ak_dasar'   => $item['ak_dasar'],
                    ]
                );
            }
        }

        // 3. MASTER PREDIKAT KINERJA
        $predikatData = [
            ['nama' => 'Sangat Baik',     'persentase_konversi' => 1.50],
            ['nama' => 'Baik',            'persentase_konversi' => 1.00],
            ['nama' => 'Butuh Perbaikan', 'persentase_konversi' => 0.75],
            ['nama' => 'Kurang',          'persentase_konversi' => 0.50],
            ['nama' => 'Sangat Kurang',   'persentase_konversi' => 0.25],
        ];

        foreach ($predikatData as $item) {
            MasterPredikatKinerja::firstOrCreate(
                ['nama' => $item['nama']],
                ['persentase_konversi' => $item['persentase_konversi']]
            );
        }
    }
}
