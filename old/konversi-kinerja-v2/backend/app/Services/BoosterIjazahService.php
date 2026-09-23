<?php

namespace App\Services;

use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanPendidikan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BoosterIjazahService
{
    protected AuditTrailService $auditTrail;

    // Bobot level strata pendidikan untuk perbandingan
    protected array $levelPendidikan = [
        'SMA' => 1,
        'D3'  => 2,
        'S1'  => 3,
        'S2'  => 4,
        'S3'  => 5,
    ];

    public function __construct(AuditTrailService $auditTrail)
    {
        $this->auditTrail = $auditTrail;
    }

    /**
     * Memeriksa 3 syarat otomatis sesuai diagram alur BKN:
     * 1. Pendidikan lebih tinggi?
     * 2. Predikat kinerja minimal Baik?
     * 3. Belum pernah dapat bonus untuk strata yang sama?
     *
     * @param Pegawai $pegawai
     * @param string $jenjangTujuan
     * @return array ['lolos' => bool, 'alasan' => string|null]
     */
    public function cekSyaratOtomatis(Pegawai $pegawai, string $jenjangTujuan): array
    {
        // 1. Cek: Pendidikan lebih tinggi?
        $levelSekarang = $this->levelPendidikan[$pegawai->pendidikan_terakhir] ?? 0;
        $levelBaru = $this->levelPendidikan[$jenjangTujuan] ?? 0;

        if ($levelBaru <= $levelSekarang) {
            return [
                'lolos' => false,
                'alasan' => "Pendidikan yang diajukan ({$jenjangTujuan}) tidak lebih tinggi dari pendidikan terakhir pegawai ({$pegawai->pendidikan_terakhir}).",
            ];
        }

        // 2. Cek: Predikat kinerja terakhir minimal 'Baik' (persentase konversi >= 100%)
        $evaluasiTerakhir = $pegawai->evaluasiKinerja()
            ->with('predikat')
            ->orderByDesc('tahun')
            ->orderByDesc('periode_bulan')
            ->first();

        if (!$evaluasiTerakhir) {
            return [
                'lolos' => false,
                'alasan' => "Pegawai belum memiliki rekam jejak evaluasi kinerja.",
            ];
        }

        if ($evaluasiTerakhir->predikat->persentase_konversi < 1.00) {
            return [
                'lolos' => false,
                'alasan' => "Predikat kinerja terakhir pegawai ({$evaluasiTerakhir->predikat->nama}) belum memenuhi syarat minimal 'Baik'.",
            ];
        }

        // 3. Cek: Belum pernah dapat bonus untuk jenjang ini?
        $pernahDapatBonus = PengajuanPendidikan::where('pegawai_id', $pegawai->id)
            ->where('jenjang_pendidikan', $jenjangTujuan)
            ->where('status', 'DISETUJUI')
            ->exists();

        if ($pernahDapatBonus) {
            return [
                'lolos' => false,
                'alasan' => "Pegawai sudah pernah memperoleh bonus Angka Kredit untuk jenjang {$jenjangTujuan} sebelumnya.",
            ];
        }

        return [
            'lolos' => true,
            'alasan' => null,
        ];
    }

    /**
     * Hitung AK Bonus: 25% dari target kebutuhan kenaikan pangkat jenjang saat ini.
     *
     * @param Pegawai $pegawai
     * @return float
     */
    public function hitungAkBonus(Pegawai $pegawai): float
    {
        $pegawai->loadMissing(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan']);
        $kebutuhanKp = $pegawai->effectiveJenjang()?->kebutuhan_ak_kp ?? 0;
        $bonus = 0.25 * $kebutuhanKp;

        return round($bonus, 2);
    }

    /**
     * Eksekusi alur persetujuan atau penolakan otomatis setelah admin memvalidasi dokumen.
     *
     * @param PengajuanPendidikan $pengajuan
     * @param User $admin
     * @return PengajuanPendidikan
     */
    public function prosesPersetujuan(PengajuanPendidikan $pengajuan, User $admin): PengajuanPendidikan
    {
        $pegawai = $pengajuan->pegawai()->with('pangkatGolongan.jenjangJabatan', 'user')->firstOrFail();

        // 1. Cek syarat otomatis
        $cek = $this->cekSyaratOtomatis($pegawai, $pengajuan->jenjang_pendidikan);

        if (!$cek['lolos']) {
            return DB::transaction(function () use ($pengajuan, $admin, $pegawai, $cek) {
                $pengajuan->update([
                    'status' => 'DITOLAK_SYARAT',
                    'catatan_verifikasi' => $cek['alasan'],
                    'diverifikasi_oleh' => $admin->id,
                    'diverifikasi_pada' => now(),
                ]);

                // Kirim notifikasi ke pegawai
                if ($pegawai->user) {
                    Notifikasi::create([
                        'user_id' => $pegawai->user->id,
                        'judul' => 'Pengajuan Peningkatan Pendidikan Ditolak Sistem',
                        'pesan' => "Pengajuan jenjang {$pengajuan->jenjang_pendidikan} tidak memenuhi syarat: {$cek['alasan']}",
                        'tipe' => 'WARNING',
                    ]);
                }

                $this->auditTrail->log(
                    'BOOSTER_IJAZAH',
                    'REJECT_SYARAT',
                    "Pengajuan pendidikan ID {$pengajuan->id} ditolak sistem: {$cek['alasan']}"
                );

                return $pengajuan;
            });
        }

        // 2. Jika lolos, hitung AK bonus 25%
        $akBonus = $this->hitungAkBonus($pegawai);

        return DB::transaction(function () use ($pengajuan, $admin, $pegawai, $akBonus) {
            // Update pengajuan
            $pengajuan->update([
                'status' => 'DISETUJUI',
                'ak_bonus' => $akBonus,
                'catatan_verifikasi' => "Disetujui. Bonus AK +{$akBonus} berhasil ditambahkan.",
                'diverifikasi_oleh' => $admin->id,
                'diverifikasi_pada' => now(),
            ]);

            // Update pendidikan terakhir pegawai
            $pegawai->update([
                'pendidikan_terakhir' => $pengajuan->jenjang_pendidikan,
            ]);

            // Update penetapan_ak untuk tahun berjalan jika ada
            $tahunBerjalan = (int) now()->year;
            $penetapan = \App\Models\PenetapanAK::firstOrCreate(
                [
                    'pegawai_id' => $pegawai->id,
                    'tahun'      => $tahunBerjalan,
                ],
                [
                    'ak_dasar'          => $pegawai->pangkatGolongan?->ak_dasar ?? 0,
                    'ak_pak_pelantikan' => 0,
                    'ak_historis'       => 0,
                    'ak_lama'           => 0,
                    'ak_baru'           => 0,
                    'ak_booster'        => 0,
                    'ak_carry_over'     => 0,
                    'ak_kumulatif'      => 0,
                ]
            );

            $totalBooster = (float) PengajuanPendidikan::where('pegawai_id', $pegawai->id)
                ->where('status', 'DISETUJUI')
                ->whereYear('diverifikasi_pada', $tahunBerjalan)
                ->sum('ak_bonus') + $akBonus;

            $penetapan->update([
                'ak_booster'   => $totalBooster,
                'ak_kumulatif' => round((float) $penetapan->ak_lama + (float) $penetapan->ak_pak_pelantikan + (float) $penetapan->ak_historis + (float) $penetapan->ak_baru + $totalBooster, 2),
            ]);

            // Kirim notifikasi sukses ke pegawai
            if ($pegawai->user) {
                Notifikasi::create([
                    'user_id' => $pegawai->user->id,
                    'judul' => 'Bonus Angka Kredit Ijazah Berhasil Diberikan!',
                    'pesan' => "Selamat! Pengajuan jenjang {$pengajuan->jenjang_pendidikan} telah disetujui. Anda memperoleh tambahan Angka Kredit Booster sebesar +{$akBonus}.",
                    'tipe' => 'SUCCESS',
                ]);
            }

            $this->auditTrail->log(
                'BOOSTER_IJAZAH',
                'APPROVE',
                "Admin {$admin->name} menyetujui pengajuan pendidikan ID {$pengajuan->id}. AK Bonus +{$akBonus} diberikan ke NIP: {$pegawai->nip}"
            );

            return $pengajuan;
        });
    }
}
