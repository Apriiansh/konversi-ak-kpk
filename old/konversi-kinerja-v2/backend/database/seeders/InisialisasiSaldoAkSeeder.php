<?php

namespace Database\Seeders;

use App\Models\Pegawai;
use App\Models\PenetapanAK;
use Illuminate\Database\Seeder;

class InisialisasiSaldoAkSeeder extends Seeder
{
    /**
     * Inisialisasi saldo AK awal setiap pegawai di awal tahun berjalan.
     *
     * - ak_dasar   : angka kredit dasar sesuai pangkat/golongan pegawai
     * - ak_lama    : akumulasi AK sebelum tahun berjalan
     * - ak_baru    : AK baru tahun berjalan (belum ada kinerja => 0)
     * - ak_kumulatif: total ak_lama + ak_baru (digunakan sebagai acuan awal)
     *
     * Idempoten: updateOrCreate per (pegawai_id, tahun) sehingga aman diulang.
     */
    public function run(): void
    {
        $tahun = 2025;

        Pegawai::query()
            ->with('pangkatGolongan')
            ->each(function (Pegawai $pegawai) use ($tahun): void {
                $akDasar = $pegawai->pangkatGolongan?->ak_dasar ?? 0;

                PenetapanAK::updateOrCreate(
                    [
                        'pegawai_id' => $pegawai->id,
                        'tahun'      => $tahun,
                    ],
                    [
                        'ak_dasar'     => $akDasar,
                        'ak_lama'      => $akDasar,
                        'ak_baru'      => 0,
                        'ak_kumulatif' => $akDasar,
                    ]
                );
            });
    }
}
