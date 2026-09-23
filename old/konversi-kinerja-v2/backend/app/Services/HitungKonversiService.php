<?php

namespace App\Services;

use App\Models\EvaluasiKinerja;
use App\Models\MasterJenjangJabatan;
use App\Models\MasterPredikatKinerja;
use App\Models\Pegawai;

class HitungKonversiService
{
    /**
     * Hitung Angka Kredit (AK) berdasarkan PerBKN 3/2023 (Formula A - Periodik).
     * Rumus: (Bulan / 12) x Persentase Predikat x Koefisien Tahunan
     *
     * @param string $pegawaiId
     * @param string $predikatId
     * @param int $periodeBulan
     * @return float
     */
    public function hitungAk(string $pegawaiId, string $predikatId, int $periodeBulan): float
    {
        $pegawai = Pegawai::with(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan'])->findOrFail($pegawaiId);
        $predikat = MasterPredikatKinerja::findOrFail($predikatId);

        $koefisienTahunan = (float) $pegawai->effectiveJenjang()->koefisien_tahunan;
        $persentaseKonversi = (float) $predikat->persentase_konversi;

        // Hitung berdasarkan rumus
        $ak = ($periodeBulan / 12) * $persentaseKonversi * $koefisienTahunan;

        return round($ak, 3);
    }

    /**
     * Hitung jumlah bulan aktif dalam tahun tertentu berdasarkan TMT Jabatan pegawai.
     *
     * @param Pegawai $pegawai
     * @param int $tahun
     * @return int
     */
    public function hitungBulanAktif(Pegawai $pegawai, int $tahun): int
    {
        if (!$pegawai->tmt_jabatan) {
            return 12;
        }

        $tmt = \Carbon\Carbon::parse($pegawai->tmt_jabatan);
        if ($tmt->year < $tahun) {
            return 12;
        }

        if ($tmt->year > $tahun) {
            return 0;
        }

        // Contoh: TMT Maret (Bulan 3) di tahun yang sama -> 12 - 3 + 1 = 10 bulan aktif
        return max(1, min(12, 12 - $tmt->month + 1));
    }

    /**
     * Formula B: Normalisasi Akhir Tahun KPK (Jika Belum Naik Jabatan).
     * Predikat TW4 bertindak sebagai tahunan retrospektif untuk menyetahunkan perolehan AK tahun berjalan.
     * Rumus: (Total Bulan Aktif / 12) x Persentase Predikat TW4 x Koefisien Tahunan
     *
     * @param string $pegawaiId
     * @param int $tahun
     * @param string|null $predikatTw4Id Jika null, akan diambil dari evaluasi TW4 terkunci
     * @return array
     */
    public function hitungAkTahunan(string $pegawaiId, int $tahun, ?string $predikatTw4Id = null): array
    {
        $pegawai = Pegawai::with(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan'])->findOrFail($pegawaiId);
        $koefisienTahunan = (float) $pegawai->effectiveJenjang()->koefisien_tahunan;

        // Cari evaluasi TW4 jika predikatTw4Id tidak disediakan langsung
        if (!$predikatTw4Id) {
            $evaluasiTw4 = EvaluasiKinerja::with('predikat')
                ->where('pegawai_id', $pegawaiId)
                ->where('tahun', $tahun)
                ->where(function ($q) {
                    $q->where('triwulan', 4)
                      ->orWhere('periode_bulan', 4)
                      ->orWhere('periode_bulan', 12);
                })
                ->orderByDesc('periode_bulan')
                ->first();

            if (!$evaluasiTw4) {
                // Ambil evaluasi terkunci terakhir di tahun tersebut sebagai fallback
                $evaluasiTw4 = EvaluasiKinerja::with('predikat')
                    ->where('pegawai_id', $pegawaiId)
                    ->where('tahun', $tahun)
                    ->orderByDesc('periode_bulan')
                    ->first();
            }

            if (!$evaluasiTw4 || !$evaluasiTw4->predikat) {
                throw new \InvalidArgumentException("Evaluasi kinerja TW4 untuk tahun {$tahun} belum ditemukan.");
            }

            $predikat = $evaluasiTw4->predikat;
        } else {
            $predikat = MasterPredikatKinerja::findOrFail($predikatTw4Id);
        }

        // Tentukan total bulan aktif dalam tahun berjalan berdasarkan TMT Jabatan
        $totalBulanAktif = $this->hitungBulanAktif($pegawai, $tahun);

        // Jika pegawai tidak memiliki TMT Jabatan yang terdefinisi, fallback ke akumulasi bulan evaluasi tercatat
        if (!$pegawai->tmt_jabatan) {
            $sumBulanEvaluasi = EvaluasiKinerja::where('pegawai_id', $pegawaiId)
                ->where('tahun', $tahun)
                ->sum('jumlah_bulan');

            if ($sumBulanEvaluasi > 0 && $sumBulanEvaluasi <= 12) {
                $totalBulanAktif = (int) $sumBulanEvaluasi;
            }
        }

        $persentaseKonversi = (float) $predikat->persentase_konversi;
        $akRaw = ($totalBulanAktif / 12) * $persentaseKonversi * $koefisienTahunan;
        $akBaru = round($akRaw, 3);

        return [
            'total_bulan_aktif'   => $totalBulanAktif,
            'predikat_tahunan'    => $predikat->nama,
            'persentase_predikat' => $persentaseKonversi,
            'koefisien_tahunan'   => $koefisienTahunan,
            'ak_baru'             => $akBaru,
            'rumus'               => "({$totalBulanAktif}/12) × {$persentaseKonversi} × {$koefisienTahunan} = {$akBaru} AK",
        ];
    }

    /**
     * Resolusi Penyesuaian Khusus Mismatch Golongan (PerBKN No. 3/2023 Lampiran II Angka 3).
     *
     * @param string                 $asalJabatan      PELAKSANA / PENGAWAS / ADMINISTRATOR / PENGANGKATAN_PERTAMA
     * @param string|null            $golongan         Golongan pegawai (mis. III/c)
     * @param MasterJenjangJabatan|null $targetJenjang Jenjang tujuan JF (Ahli Pertama / Ahli Muda / dll)
     * @return array{blocked:bool, block_message:?string, flat:bool, note:?string}
     */
    public function resolveMismatchPenyesuaian(string $asalJabatan, ?string $golongan, $targetJenjang): array
    {
        $asal = strtoupper($asalJabatan);
        $namaJenjang = strtolower((string) ($targetJenjang->nama ?? ''));

        // Kasus A: Pelaksana dilarang melompat ke jenjang selain Ahli Pertama.
        if ($asal === 'PELAKSANA' && $namaJenjang !== '' && $namaJenjang !== 'ahli pertama') {
            return [
                'blocked'        => true,
                'block_message'  => 'Pelaksana hanya dapat diangkat ke jenjang Ahli Pertama. Target "' . ($targetJenjang?->nama ?? '-'),
                'flat'           => false,
                'note'           => null,
            ];
        }

        // Kasus B: Pelaksana gol. III/c, III/d, IV/a -> Ahli Pertama => 100 AK flat.
        $golonganFlat = ['iii/c', 'iii/d', 'iv/a'];
        if ($asal === 'PELAKSANA' && $namaJenjang === 'ahli pertama'
            && in_array(strtolower((string) $golongan), $golonganFlat, true)) {
            return [
                'blocked'       => false,
                'block_message' => null,
                'flat'          => true,
                'note'          => 'Penyesuaian Perpindahan Jabatan (PerBKN No. 3/2023 Lampiran II)',
            ];
        }

        return [
            'blocked'       => false,
            'block_message' => null,
            'flat'          => false,
            'note'          => null,
        ];
    }

    /**
     * Hitung PAK Awal Pelantikan dari masa kerja jenjang sebelumnya (Formula C).
     *
     * Rumus Normal: (Tahun × Predikat × Koefisien) + ((Bulan/12) × Predikat × Koefisien)
     *
     * Pengecualian Regulasi — PerBKN No. 3/2023 Lampiran II Angka 3:
     * Apabila golongan Pelaksana tidak sesuai dengan jenjang tujuan JF (misalnya Pelaksana
     * gol. III/c, III/d, atau IV/a yang masuk ke Ahli Pertama), maka SELURUH masa kerja
     * sebelumnya DIABAIKAN dan PAK Pelantikan ditetapkan 100 AK secara FLAT (non-Formula C).
     *
     * @param int   $masaKerjaTahun
     * @param int   $masaKerjaBulan
     * @param float $persentasePredikat
     * @param float $koefisienTahunan
     * @param bool  $isMismatchFlat   Jika true, abaikan rumus & kembalikan 100 AK flat
     * @return array
     */
    public function hitungPakPelantikan(
        int $masaKerjaTahun,
        int $masaKerjaBulan,
        float $persentasePredikat = 1.0,
        float $koefisienTahunan = 12.5,
        bool $isMismatchFlat = false
    ): array {
        // ── Guard Regulasi: Pelaksana golongan tidak sesuai jenjang tujuan ──────
        // Dasar hukum: PerBKN No. 3/2023 Lampiran II Angka 3 (Tabel AK Khusus)
        // Pelaksana III/c, III/d, IV/a → Ahli Pertama = 100 AK flat, masa kerja diabaikan.
        if ($isMismatchFlat) {
            return [
                'masa_kerja_tahun'  => $masaKerjaTahun,
                'masa_kerja_bulan'  => $masaKerjaBulan,
                'ak_tahun'          => 100.00,
                'ak_bulan'          => 0.00,
                'total_ak_pak'      => 100.00,
                'is_mismatch_flat'  => true,
                'catatan_regulasi'  => 'Penyesuaian Perpindahan Jabatan: Pelaksana (gol. tidak sesuai jenjang) → Ahli Pertama dalam rangka penyesuaian angka kredit kumulatif perpindahan khusus, ditetapkan 100 AK sesuai PerBKN No. 3/2023 Lampiran II Angka 3. Masa kerja sebelumnya tidak diperhitungkan.',
            ];
        }

        // ── Formula C Normal ──────────────────────────────────────────────────
        $akTahun = $masaKerjaTahun * $persentasePredikat * $koefisienTahunan;
        $akBulan = ($masaKerjaBulan / 12) * $persentasePredikat * $koefisienTahunan;

        $akTahunRounded = round($akTahun, 3);
        $akBulanRounded = round($akBulan, 3);
        $totalPak = round($akTahunRounded + $akBulanRounded, 3);

        return [
            'masa_kerja_tahun'  => $masaKerjaTahun,
            'masa_kerja_bulan'  => $masaKerjaBulan,
            'ak_tahun'          => $akTahunRounded,
            'ak_bulan'          => $akBulanRounded,
            'total_ak_pak'      => $totalPak,
            'is_mismatch_flat'  => false,
            'catatan_regulasi'  => null,
        ];
    }
}
