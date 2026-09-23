<?php

namespace App\Services;

use App\Models\Pegawai;
use App\Models\MasterJenjangJabatan;

class CarryOverService
{
    /**
     * Evaluasi kelayakan kenaikan pangkat / jenjang dan hitung deposit carry-over ke tahun berikutnya.
     *
     * Aturan KPK / BKN:
     * 1. Kenaikan Pangkat (KP): Jika ak_kumulatif >= target_kp, sisa AK (ak_kumulatif - target_kp) dibawa sebagai deposit carry-over.
     * 2. Kenaikan Jenjang (Promosi): Jika ak_kumulatif >= target_jenjang, sisa AK TIDAK BOLEH ditabung (carry_over = 0 / HANGUS).
     * 3. Belum Cukup: ak_kumulatif dibawa utuh sebagai saldo awal tahun berikutnya.
     *
     * @param Pegawai $pegawai
     * @param float $akKumulatif
     * @param MasterJenjangJabatan|null $targetJenjang Jenjang tujuan import, jika berbeda dari jenjang asal
     * @return array
     */
    public function evaluasiKelayakan(
        Pegawai $pegawai,
        float $akKumulatif,
        ?MasterJenjangJabatan $targetJenjang = null
    ): array
    {
        $pegawai->loadMissing(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan']);
        $jenjang = $pegawai->effectiveJenjang();
        $jenjangAsal = $pegawai->pangkatGolongan?->jenjangJabatan;
        $isKenaikanJenjang = $targetJenjang
            && $jenjangAsal
            && $targetJenjang->id !== $jenjangAsal->id;
        $jenisTarget = $isKenaikanJenjang ? 'JENJANG' : 'PANGKAT';

        $targetKp = (float) ($jenjangAsal?->kebutuhan_ak_kp ?? $jenjang->kebutuhan_ak_kp ?? 50.0);
        $targetJenjangAk = (float) ($jenjangAsal?->kebutuhan_ak_jenjang ?? $jenjang->kebutuhan_ak_jenjang ?? 100.0);

        $status = 'BELUM_CUKUP';
        $badgeLabel = 'BELUM CUKUP AK UNTUK NAIK JENJANG';
        $badgeColor = 'warning'; // or red/orange in UI
        $carryOver = 0.0;
        $keterangan = '';
        $selisih = 0.0;

        // Saat target jenjang diisi dan berbeda dari jenjang asal, hanya promosi yang dinilai.
        if ($isKenaikanJenjang && $targetJenjangAk < 9999 && $akKumulatif >= $targetJenjangAk) {
            $status = 'LAYAK_JENJANG';
            $badgeLabel = 'LAYAK NAIK JENJANG';
            $badgeColor = 'success';
            $carryOver = 0.0; // Hangus sesuai regulasi
            $keterangan = "Selamat! Pegawai telah memenuhi syarat AK untuk Kenaikan Jenjang Jabatan (Target: {$targetJenjangAk} AK). Sisa kelebihan AK direset ke 0 (hangus).";
        } elseif ($isKenaikanJenjang) {
            $status = 'BELUM_CUKUP';
            $badgeLabel = 'BELUM CUKUP AK UNTUK NAIK JENJANG';
            $badgeColor = 'secondary';
            $selisih = round($targetJenjangAk - $akKumulatif, 2);
            $carryOver = round($akKumulatif, 2);
            $keterangan = "Angka Kredit belum mencukupi untuk Kenaikan Jenjang Jabatan. Kurang {$selisih} AK dari target {$targetJenjangAk} AK. Seluruh saldo {$carryOver} AK disimpan untuk tahun depan.";
        } elseif ($targetJenjangAk < 9999 && $akKumulatif >= $targetJenjangAk) {
            $status = 'LAYAK_JENJANG';
            $badgeLabel = 'LAYAK NAIK JENJANG';
            $badgeColor = 'success';
            $carryOver = 0.0; // Hangus sesuai regulasi
            $keterangan = "Selamat! Pegawai telah memenuhi syarat AK untuk Kenaikan Jenjang Jabatan (Target: {$targetJenjangAk} AK). Sisa kelebihan AK direset ke 0 (hangus).";
        } elseif ($akKumulatif >= $targetKp) {
            $status = 'LAYAK_PANGKAT';
            $badgeLabel = 'LAYAK NAIK PANGKAT';
            $badgeColor = 'success';
            $carryOver = round($akKumulatif - $targetKp, 3);
            $keterangan = "Selamat! Pegawai telah memenuhi syarat AK untuk Kenaikan Pangkat (Target: {$targetKp} AK). Sisa tabungan AK sebesar {$carryOver} AK akan dibawa ke periode berikutnya.";
        } else {
            $status = 'BELUM_CUKUP';
            $badgeLabel = 'BELUM CUKUP AK UNTUK NAIK PANGKAT';
            $badgeColor = 'secondary';
            $selisih = round($targetKp - $akKumulatif, 3);
            $carryOver = round($akKumulatif, 3); // Dibawa utuh karena belum naik pangkat
            $keterangan = "Angka Kredit belum mencukupi untuk Kenaikan Pangkat. Kurang {$selisih} AK dari target {$targetKp} AK. Seluruh saldo {$carryOver} AK disimpan untuk tahun depan.";
        }

        return [
            'status'          => $status,
            'badge_label'     => $badgeLabel,
            'badge_color'     => $badgeColor,
            'target_kp'       => $targetKp,
            'target_jenjang'  => $targetJenjangAk,
            'jenis_target'    => $jenisTarget,
            'ak_kumulatif'    => $akKumulatif,
            'carry_over'      => $carryOver,
            'kurang_ak'       => $selisih,
            'catatan'         => $keterangan,
        ];
    }
}
