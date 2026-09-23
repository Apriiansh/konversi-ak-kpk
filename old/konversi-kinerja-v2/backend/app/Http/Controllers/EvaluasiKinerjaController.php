<?php

namespace App\Http\Controllers;

use App\Models\EvaluasiKinerja;
use App\Models\MasterPangkatGolongan;
use App\Models\MasterPredikatKinerja;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PenetapanAK;
use App\Models\PengajuanPendidikan;
use App\Services\AuditTrailService;
use App\Services\CarryOverService;
use App\Services\HitungKonversiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvaluasiKinerjaController extends Controller
{
    protected HitungKonversiService $konversiService;
    protected CarryOverService $carryOverService;
    protected AuditTrailService $auditTrail;

    public function __construct(
        HitungKonversiService $konversiService,
        CarryOverService $carryOverService,
        AuditTrailService $auditTrail
    ) {
        $this->konversiService = $konversiService;
        $this->carryOverService = $carryOverService;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Daftar evaluasi kinerja — Admin melihat semua, Pegawai melihat miliknya.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = EvaluasiKinerja::with([
            'pegawai:id,nama_lengkap,nip',
            'atasanPenilai:id,nama_lengkap',
            'predikat:id,nama,persentase_konversi',
        ])->latest();

        if ($user->role !== 'ADMIN') {
            $pegawai = $user->pegawai()->first();
            if (!$pegawai) {
                return response()->json(['data' => []], 200);
            }
            $query->where('pegawai_id', $pegawai->id);
        }

        return response()->json([
            'message' => 'Daftar evaluasi kinerja.',
            'data'    => $query->paginate($request->input('per_page', 15)),
        ]);
    }

    /**
     * Simpan evaluasi kinerja triwulanan/periodik & hitung AK otomatis menggunakan rumus BKN.
     * Rumus: (Bulan / 12) x Persentase_Predikat x Koefisien_Tahunan_Jenjang
     */
    /**
     * Simpan evaluasi kinerja triwulanan/periodik & hitung AK otomatis menggunakan rumus BKN.
     * Rumus: (Bulan / 12) x Persentase_Predikat x Koefisien_Tahunan_Jenjang
     * Jika triwulan 4 / tahunan, otomatis rekonsiliasi proporsional & langsung finalisasi + deposit carry over.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pegawai_id'    => 'required|uuid|exists:pegawai,id',
            'tahun'         => 'required|integer|min:2020',
            'triwulan'      => 'nullable|integer|min:1|max:4',
            'periode_bulan' => 'nullable|integer|min:1|max:12',
            'jumlah_bulan'  => 'nullable|integer|min:1|max:12',
            'predikat_id'   => 'required|uuid|exists:master_predikat_kinerja,id',
            'tipe'          => 'nullable|in:periodik,tahunan',
        ]);

        if ($this->isTahunTerkunci($validated['pegawai_id'], (int) $validated['tahun'])) {
            return response()->json(['message' => 'Tahun sudah terkunci. Evaluasi tidak dapat disimpan.'], 422);
        }

        // Default jumlah_bulan & triwulan jika salah satu diisi
        $triwulan = $validated['triwulan'] ?? (isset($validated['periode_bulan']) ? (int) ceil($validated['periode_bulan'] / 3) : 1);
        $jumlahBulan = $validated['jumlah_bulan'] ?? ($validated['periode_bulan'] ?? 3);
        $periodeBulan = $validated['periode_bulan'] ?? ($triwulan * 3);

        // Ambil atasan penilai dari relasi user yang sedang login
        $atasanPenilaiId = $request->user()->pegawai()->first()?->id;
        $tipe = $validated['tipe'] ?? 'periodik';

        // Jika TW4 atau mode tahunan: alur sekali jalan dan tuntas (rekonsiliasi & finalisasi langsung)
        if ($triwulan === 4 || $tipe === 'tahunan') {
            $pegawai = Pegawai::with(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan', 'user'])->findOrFail($validated['pegawai_id']);
            $existingTw4 = EvaluasiKinerja::where('pegawai_id', $pegawai->id)
                ->where('tahun', (int) $validated['tahun'])
                ->where('triwulan', 4)
                ->first();

            $result = DB::transaction(fn () => $this->processTahunanTw4(
                $pegawai,
                (int) $validated['tahun'],
                $validated['predikat_id'],
                $atasanPenilaiId,
                $existingTw4,
                $request->user()->id
            ));

            return response()->json([
                'message'   => 'Evaluasi Kinerja tahunan berhasil disimpan dan Penetapan AK telah difinalisasi.',
                'data'      => $result['evaluasi']->load('predikat'),
                'penetapan' => $result['penetapan'],
                'kelayakan' => $result['kelayakan'],
            ], 201);
        }

        // Hitung AK melalui service (Formula A)
        $angkaKredit = $this->konversiService->hitungAk(
            $validated['pegawai_id'],
            $validated['predikat_id'],
            $jumlahBulan
        );

        $evaluasi = DB::transaction(function () use ($validated, $atasanPenilaiId, $angkaKredit, $triwulan, $jumlahBulan, $periodeBulan) {
            $data = EvaluasiKinerja::create([
                'pegawai_id'        => $validated['pegawai_id'],
                'atasan_penilai_id' => $atasanPenilaiId,
                'tahun'             => $validated['tahun'],
                'triwulan'          => $triwulan,
                'periode_bulan'     => $periodeBulan,
                'jumlah_bulan'      => $jumlahBulan,
                'predikat_id'       => $validated['predikat_id'],
                'angka_kredit'      => $angkaKredit,
            ]);

            $this->auditTrail->log(
                'EVALUASI_KINERJA',
                'CREATE',
                "Membuat evaluasi kinerja TW{$triwulan} ({$jumlahBulan} bln) untuk pegawai ID: {$validated['pegawai_id']} | AK: {$angkaKredit}",
                null,
                $data->toArray()
            );

            return $data;
        });

        // Sinkronisasi otomatis ke PenetapanAK agar progress bar dan live AK langsung terupdate
        $this->syncPenetapanAK($validated['pegawai_id'], (int) $validated['tahun']);

        return response()->json([
            'message' => 'Evaluasi Kinerja berhasil disimpan.',
            'data'    => $evaluasi->load('predikat'),
        ], 201);
    }

    /**
     * Simulasi perhitungan konversi AK tanpa menyimpan ke database.
     * Berguna untuk preview hasil sebelum user menekan tombol simpan.
     */
    public function simulasi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pegawai_id'    => 'required|uuid|exists:pegawai,id',
            'predikat_id'   => 'required|uuid|exists:master_predikat_kinerja,id',
            'triwulan'      => 'nullable|integer|min:1|max:4',
            'periode_bulan' => 'nullable|integer|min:1|max:12',
            'jumlah_bulan'  => 'nullable|integer|min:1|max:12',
            'tipe'          => 'nullable|in:periodik,tahunan',
            'tahun'         => 'nullable|integer',
        ]);

        $jumlahBulan = $validated['jumlah_bulan'] ?? ($validated['periode_bulan'] ?? 3);
        $pegawai = Pegawai::with(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan'])->findOrFail($validated['pegawai_id']);

        $jenjang  = $pegawai->effectiveJenjang();
        $pangkat  = $pegawai->pangkatGolongan;

        if (($validated['tipe'] ?? 'periodik') === 'tahunan') {
            $tahun = $validated['tahun'] ?? (int) now()->year;
            $hasilTahunan = $this->konversiService->hitungAkTahunan($pegawai->id, $tahun, $validated['predikat_id']);

            return response()->json([
                'message' => 'Hasil simulasi konversi Angka Kredit Tahunan (Formula B - Predikat Tahunan).',
                'data'    => [
                    'pegawai'                   => $pegawai->nama_lengkap,
                    'jenjang'                   => $jenjang->nama,
                    'golongan'                  => $pangkat->golongan,
                    'koefisien_tahunan'         => $jenjang->koefisien_tahunan,
                    'total_bulan_aktif'         => $hasilTahunan['total_bulan_aktif'],
                    'predikat_tahunan'          => $hasilTahunan['predikat_tahunan'],
                    'angka_kredit'              => $hasilTahunan['ak_baru'],
                    'rumus'                     => $hasilTahunan['rumus'],
                    'kebutuhan_ak_kp'           => $jenjang->kebutuhan_ak_kp,
                    'kebutuhan_ak_naik_jenjang' => $jenjang->kebutuhan_ak_jenjang,
                ],
            ]);
        }

        $akHasil = $this->konversiService->hitungAk(
            $validated['pegawai_id'],
            $validated['predikat_id'],
            $jumlahBulan
        );

        $predikat = MasterPredikatKinerja::findOrFail($validated['predikat_id']);
        $persentaseKonversi = (float) $predikat->persentase_konversi;

        return response()->json([
            'message' => 'Hasil simulasi konversi Angka Kredit Periodik (Formula A).',
            'data'    => [
                'pegawai'                   => $pegawai->nama_lengkap,
                'jenjang'                   => $jenjang->nama,
                'golongan'                  => $pangkat->golongan,
                'koefisien_tahunan'         => $jenjang->koefisien_tahunan,
                'jumlah_bulan'              => $jumlahBulan,
                'angka_kredit'              => $akHasil,
                'rumus'                     => "({$jumlahBulan}/12) × {$persentaseKonversi} × {$jenjang->koefisien_tahunan} = {$akHasil} AK",
                'kebutuhan_ak_kp'           => $jenjang->kebutuhan_ak_kp,
                'kebutuhan_ak_naik_jenjang' => $jenjang->kebutuhan_ak_jenjang,
            ],
        ]);
    }

    /**
     * Detail satu evaluasi kinerja.
     */
    public function show(string $id): JsonResponse
    {
        $evaluasi = EvaluasiKinerja::with([
            'pegawai:id,nama_lengkap,nip',
            'atasanPenilai:id,nama_lengkap',
            'predikat:id,nama,persentase_konversi',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail evaluasi kinerja.',
            'data'    => $evaluasi,
        ]);
    }

    /**
     * Update predikat evaluasi kinerja (ditolak jika tahun terkunci).
     * AK akan dihitung ulang secara otomatis.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $evaluasi = EvaluasiKinerja::findOrFail($id);

        if ($this->isTahunTerkunci($evaluasi->pegawai_id, (int) $evaluasi->tahun)) {
            return response()->json(['message' => 'Tahun sudah terkunci. Evaluasi tidak dapat diubah.'], 422);
        }

        $validated = $request->validate([
            'predikat_id'  => 'required|uuid|exists:master_predikat_kinerja,id',
            'jumlah_bulan' => 'nullable|integer|min:1|max:12',
            'tipe'         => 'nullable|in:periodik,tahunan',
        ]);

        $tipe = $validated['tipe'] ?? null;
        $isTahunan = $tipe === 'tahunan' || ((int) $evaluasi->triwulan === 4 && $tipe !== 'periodik' && !isset($validated['jumlah_bulan']));

        // Jika mode tahunan: jalankan alur tahunan sekali jalan dan tuntas
        if ($isTahunan) {
            $pegawai = Pegawai::with(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan', 'user'])->findOrFail($evaluasi->pegawai_id);

            $result = DB::transaction(fn () => $this->processTahunanTw4(
                $pegawai,
                (int) $evaluasi->tahun,
                $validated['predikat_id'],
                null,
                $evaluasi,
                $request->user()->id
            ));

            return response()->json([
                'message'   => 'Evaluasi kinerja tahunan berhasil diperbarui dan Penetapan AK telah difinalisasi.',
                'data'      => $result['evaluasi']->load('predikat'),
                'penetapan' => $result['penetapan'],
                'kelayakan' => $result['kelayakan'],
            ]);
        }

        $jumlahBulan = $validated['jumlah_bulan'] ?? $evaluasi->jumlah_bulan;
        $periodeBulan = $evaluasi->periode_bulan;

        $angkaKredit = $this->konversiService->hitungAk(
            $evaluasi->pegawai_id,
            $validated['predikat_id'],
            $jumlahBulan
        );

        $sebelumnya = $evaluasi->toArray();
        $evaluasi->update([
            'predikat_id'   => $validated['predikat_id'],
            'jumlah_bulan'  => $jumlahBulan,
            'periode_bulan' => $periodeBulan,
            'angka_kredit'  => $angkaKredit,
        ]);

        $this->auditTrail->log(
            'EVALUASI_KINERJA',
            'UPDATE',
            "Memperbarui evaluasi kinerja TW{$evaluasi->triwulan} ID: {$id} | AK baru: {$angkaKredit}",
            $sebelumnya,
            $evaluasi->fresh()->toArray()
        );

        // Sinkronisasi otomatis ke PenetapanAK
        $this->syncPenetapanAK($evaluasi->pegawai_id, (int) $evaluasi->tahun);

        return response()->json([
            'message' => 'Evaluasi kinerja berhasil diperbarui.',
            'data'    => $evaluasi->load('predikat'),
        ]);
    }

    /**
     * Hapus evaluasi kinerja (ditolak jika tahun terkunci).
     */
    public function destroy(string $id): JsonResponse
    {
        $evaluasi = EvaluasiKinerja::findOrFail($id);

        if ($this->isTahunTerkunci($evaluasi->pegawai_id, (int) $evaluasi->tahun)) {
            return response()->json(['message' => 'Tahun sudah terkunci. Evaluasi tidak dapat dihapus.'], 422);
        }

        $pegawaiId = $evaluasi->pegawai_id;
        $tahun = (int) $evaluasi->tahun;

        $this->auditTrail->log(
            'EVALUASI_KINERJA',
            'DELETE',
            "Menghapus evaluasi kinerja TW{$evaluasi->triwulan} tahun {$evaluasi->tahun} untuk pegawai ID: {$evaluasi->pegawai_id}",
            $evaluasi->toArray(),
            null
        );

        $evaluasi->delete();

        // Sinkronisasi otomatis ke PenetapanAK agar progress bar dan live AK langsung terupdate & status final batal
        $this->syncPenetapanAK($pegawaiId, $tahun);

        return response()->json(['message' => 'Evaluasi kinerja berhasil dihapus.']);
    }

    /**
     * Konteks lengkap input kinerja satu pegawai untuk tahun tertentu.
     * Mengembalikan: profil, distribusi bulan per TW, evaluasi existing, predikat master, TW aktif server.
     */
    public function context(Request $request, string $pegawaiId, int $tahun): JsonResponse
    {
        $pegawai = Pegawai::with([
            'pangkatGolongan.jenjangJabatan',
            'jenjangJabatan',
            'user:id,name,email',
        ])->findOrFail($pegawaiId);

        $jenjang = $pegawai->effectiveJenjang();
        $pangkat = $pegawai->pangkatGolongan;

        // Distribusi bulan aktif per TW dari TMT Jabatan
        $bulanPerTw = $this->hitungBulanAktifDariTmt($pegawai->tmt_jabatan?->format('Y-m-d'), $tahun);

        // Evaluasi yang sudah ada (TW1–TW4) untuk tahun ini
        $evaluasiExisting = EvaluasiKinerja::with('predikat:id,nama,persentase_konversi')
            ->where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->orderBy('triwulan')
            ->get()
            ->keyBy('triwulan');

        // Status kelayakan saat ini dari penetapan_ak draft
        $penetapan = \App\Models\PenetapanAK::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->first();

        $saldoAwal = $penetapan
            ? (float)$penetapan->ak_dasar + (float)$penetapan->ak_pak_pelantikan + (float)$penetapan->ak_historis + (float)$penetapan->ak_lama
            : ((float)($pangkat?->ak_dasar ?? 0));

        // TW aktif di server berdasarkan bulan saat ini
        $bulanSekarang = (int) now()->month;
        $twAktif = match (true) {
            $bulanSekarang <= 3  => 1,
            $bulanSekarang <= 6  => 2,
            $bulanSekarang <= 9  => 3,
            default              => 4,
        };

        // Cek eligibilitas TW3 (sudah layak naik sebelum TW4?) — hanya periodik TW1–TW3
        $sumAkPeriodik = $evaluasiExisting->where('triwulan', '<', 4)->sum('angka_kredit');
        $akKumulatifDraft = $saldoAwal + $sumAkPeriodik;
        $targetEvaluasiJenjang = $this->getTargetEvaluasiJenjang($pegawai);
        $targetKp = (float)($jenjang?->kebutuhan_ak_kp ?? 50.0);
        $targetJenjang = (float)($jenjang?->kebutuhan_ak_jenjang ?? 100.0);

        $kelayakanDraft = $this->carryOverService->evaluasiKelayakan($pegawai, $akKumulatifDraft, $targetEvaluasiJenjang);
        $sudahLayakSebelumTw4 = in_array($kelayakanDraft['status'], ['LAYAK_PANGKAT', 'LAYAK_JENJANG'], true);

        return response()->json([
            'message' => 'Konteks input kinerja pegawai.',
            'data'    => [
                'pegawai' => [
                    'id'                  => $pegawai->id,
                    'nip'                 => $pegawai->nip,
                    'nama_lengkap'        => $pegawai->nama_lengkap,
                    'email'               => $pegawai->user?->email,
                    'golongan'            => $pangkat?->golongan,
                    'jenjang'             => $jenjang?->nama,
                    'asal_jabatan'        => $pegawai->asal_jabatan,
                    'pendidikan_terakhir' => $pegawai->pendidikan_terakhir,
                    'tmt_jabatan'         => $pegawai->tmt_jabatan?->format('Y-m-d'),
                    'pangkat_golongan_id' => $pegawai->pangkat_golongan_id,
                    'jenjang_jabatan_id'  => $pegawai->jenjang_jabatan_id,
                    'koefisien_tahunan'   => $jenjang?->koefisien_tahunan,
                    'kebutuhan_ak_kp'     => $targetKp,
                    'kebutuhan_ak_jenjang'=> $targetJenjang,
                ],
                'saldo_awal'               => $saldoAwal,
                'ak_kumulatif_draft'       => round($akKumulatifDraft, 3),
                'sudah_layak_sebelum_tw4'  => $sudahLayakSebelumTw4,
                'tw_aktif'                 => $twAktif,
                'tahun'                    => $tahun,
                'bulan_per_tw'             => $bulanPerTw ?? [1 => 3, 2 => 3, 3 => 3, 4 => 3],
                'evaluasi'                 => $evaluasiExisting->map(fn($e) => [
                    'id'           => $e->id,
                    'triwulan'     => $e->triwulan,
                    'jumlah_bulan' => $e->jumlah_bulan,
                    'predikat_id'  => $e->predikat_id,
                    'predikat'     => $e->predikat?->nama,
                    'angka_kredit' => (float) $e->angka_kredit,
                ]),
                'penetapan_is_final' => (bool) $penetapan?->is_final,
                'penetapan_is_locked' => (bool) $penetapan?->is_locked,
            ],
        ]);
    }

    /**
     * Hitung distribusi bulan aktif per triwulan dari TMT Jabatan.
     */
    protected function hitungBulanAktifDariTmt(?string $tmt, int $tahun): ?array
    {
        if (empty($tmt)) {
            return null;
        }

        try {
            $date = \Carbon\Carbon::parse($tmt);
        } catch (\Throwable $e) {
            return null;
        }

        $tmtTahun = (int) $date->year;

        if ($tmtTahun < $tahun) {
            return [1 => 3, 2 => 3, 3 => 3, 4 => 3];
        }
        if ($tmtTahun > $tahun) {
            return null;
        }

        $startMonth = (int) $date->month;
        $bulan = [];
        foreach (range(1, 4) as $q) {
            $from = (($q - 1) * 3) + 1;
            $to   = $q * 3;
            if ($startMonth > $to) {
                $bulan[$q] = 0;
            } elseif ($startMonth <= $from) {
                $bulan[$q] = 3;
            } else {
                $bulan[$q] = $to - $startMonth + 1;
            }
        }

        return $bulan;
    }

    /**
     * Cek apakah tahun penetapan AK pegawai sedang terkunci.
     */
    protected function isTahunTerkunci(string $pegawaiId, int $tahun): bool
    {
        return (bool) PenetapanAK::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->value('is_locked');
    }

    /**
     * Resolusi jenjang evaluasi tujuan jika pangkat berada di batas akhir jenjang.
     */
    protected function getTargetEvaluasiJenjang(Pegawai $pegawai): ?\App\Models\MasterJenjangJabatan
    {
        $pangkat = $pegawai->pangkatGolongan;
        $jenjangAsal = $pangkat?->jenjangJabatan;
        $targetJenjang = $pegawai->jenjangJabatan;

        $golonganBerikutnya = [
            'iii/a' => 'III/b',
            'iii/b' => 'III/c',
            'iii/c' => 'III/d',
            'iii/d' => 'IV/a',
            'iv/a' => 'IV/b',
            'iv/b' => 'IV/c',
            'iv/c' => 'IV/d',
            'iv/d' => 'IV/e',
        ];

        $nextGolongan = $golonganBerikutnya[strtolower($pangkat?->golongan ?? '')] ?? null;
        $nextPangkat = $nextGolongan
            ? MasterPangkatGolongan::with('jenjangJabatan')->whereRaw('LOWER(golongan) = ?', [strtolower($nextGolongan)])->first()
            : null;

        $targetEvaluasiJenjang = $targetJenjang;
        if (
            $nextPangkat
            && $jenjangAsal
            && $nextPangkat->jenjangJabatan
            && $nextPangkat->jenjangJabatan->id !== $jenjangAsal->id
            && (!$targetJenjang || $targetJenjang->id === $jenjangAsal->id)
        ) {
            $targetEvaluasiJenjang = $nextPangkat->jenjangJabatan;
        }

        return $targetEvaluasiJenjang;
    }

    /**
     * Proses evaluasi tahunan / TW4 secara utuh:
     * - Cek kelayakan TW3 (Formula A vs Formula B).
     * - Rekonsiliasi proporsional TW1..TW4 di database (mencegah double count).
     * - Auto-finalisasi PenetapanAK & year-lock.
     * - Auto-deposit carry-over ke tahun berikutnya (Tahun + 1).
     * - Notifikasi ke pegawai & audit trail.
     */
    protected function processTahunanTw4(
        Pegawai $pegawai,
        int $tahun,
        string $predikatId,
        ?string $atasanPenilaiId = null,
        ?EvaluasiKinerja $existingTw4 = null,
        $userId = null
    ): array {
        $targetEvaluasiJenjang = $this->getTargetEvaluasiJenjang($pegawai);

        $penetapan = PenetapanAK::firstOrCreate(
            [
                'pegawai_id' => $pegawai->id,
                'tahun'      => $tahun,
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
                'status_kelayakan'  => 'BELUM_CUKUP',
                'is_final'          => false,
            ]
        );

        $akLamaEffective = (float) $penetapan->ak_lama > 0
            ? (float) $penetapan->ak_lama
            : (float) $penetapan->ak_dasar + (float) $penetapan->ak_pak_pelantikan + (float) $penetapan->ak_historis + (float) $penetapan->ak_carry_over;

        $liveAkBooster = (float) PengajuanPendidikan::where('pegawai_id', $pegawai->id)
            ->where('status', 'DISETUJUI')
            ->where(function ($q) use ($tahun) {
                $q->whereYear('diverifikasi_pada', $tahun)
                  ->orWhereYear('created_at', $tahun);
            })
            ->sum('ak_bonus');

        $akTw1_3 = (float) EvaluasiKinerja::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahun)
            ->whereIn('triwulan', [1, 2, 3])
            ->sum('angka_kredit');

        $akKumulatifTw3 = round($akLamaEffective + $akTw1_3 + $liveAkBooster, 3);
        $kelayakanTw3 = $this->carryOverService->evaluasiKelayakan($pegawai, $akKumulatifTw3, $targetEvaluasiJenjang);
        $sudahLayakTw3 = in_array($kelayakanTw3['status'], ['LAYAK_PANGKAT', 'LAYAK_JENJANG'], true);

        $bulanPerTw = $this->hitungBulanAktifDariTmt($pegawai->tmt_jabatan?->format('Y-m-d'), $tahun) ?? [1 => 3, 2 => 3, 3 => 3, 4 => 3];
        $bulanTw4 = (int) ($bulanPerTw[4] ?? 3);

        $existingTw1_3 = EvaluasiKinerja::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahun)
            ->whereIn('triwulan', [1, 2, 3])
            ->get();

        if ($sudahLayakTw3) {
            // Formula A: Periode murni
            $akTw4 = $this->konversiService->hitungAk($pegawai->id, $predikatId, $bulanTw4);
            $akBaru = round($akTw1_3 + $akTw4, 3);
            $tw4JumlahBulan = $bulanTw4;
        } else {
            // Formula B: Penyetahunan penuh acuan TW4
            $hasilTahunan = $this->konversiService->hitungAkTahunan($pegawai->id, $tahun, $predikatId);
            $akBaru = (float) $hasilTahunan['ak_baru'];
            $totalBulanAktif = (int) $hasilTahunan['total_bulan_aktif'];

            if ($existingTw1_3->isNotEmpty()) {
                // Rekonsiliasi proporsional ke TW1..TW3 yang ada di database agar sum(angka_kredit) persis sama dengan akBaru
                $akTerbagi = 0.0;
                foreach (range(1, 3) as $q) {
                    $evalTwQ = $existingTw1_3->firstWhere('triwulan', $q);
                    if ($evalTwQ) {
                        $b = (int) ($bulanPerTw[$q] ?? $evalTwQ->jumlah_bulan ?? 3);
                        $akQ = ($b > 0 && $totalBulanAktif > 0)
                            ? round($akBaru * ($b / $totalBulanAktif), 3)
                            : 0.0;

                        $evalTwQ->update(['angka_kredit' => $akQ]);
                        $akTerbagi += $akQ;
                    }
                }

                $akTw4 = $bulanTw4 > 0 ? round($akBaru - $akTerbagi, 3) : 0.0;
                $tw4JumlahBulan = $bulanTw4;
            } else {
                // TW1..TW3 tidak diinput terpisah -> TW4 merepresentasikan evaluasi setahun penuh
                $akTw4 = $akBaru;
                $tw4JumlahBulan = $totalBulanAktif;
            }
        }

        // Simpan / update record TW4
        if ($existingTw4) {
            $existingTw4->update([
                'predikat_id'   => $predikatId,
                'periode_bulan' => 12,
                'jumlah_bulan'  => $tw4JumlahBulan,
                'angka_kredit'  => $akTw4,
            ]);
            $evaluasiTw4 = $existingTw4->fresh();
        } else {
            $evaluasiTw4 = EvaluasiKinerja::updateOrCreate(
                [
                    'pegawai_id' => $pegawai->id,
                    'tahun'      => $tahun,
                    'triwulan'   => 4,
                ],
                [
                    'atasan_penilai_id' => $atasanPenilaiId,
                    'periode_bulan'     => 12,
                    'jumlah_bulan'      => $tw4JumlahBulan,
                    'predikat_id'       => $predikatId,
                    'angka_kredit'      => $akTw4,
                ]
            );
        }

        // Total AK Kumulatif Akhir
        $akKumulatif = round($akLamaEffective + $akBaru + $liveAkBooster, 3);
        $kelayakanAkhir = $this->carryOverService->evaluasiKelayakan($pegawai, $akKumulatif, $targetEvaluasiJenjang);

        // Finalisasi tahunan PenetapanAK (identik dengan ImportKonversiService)
        $penetapan->update([
            'ak_baru'           => $akBaru,
            'ak_booster'        => $liveAkBooster,
            'ak_kumulatif'      => $akKumulatif,
            'status_kelayakan'  => $kelayakanAkhir['status'],
            'catatan_kelayakan' => $kelayakanAkhir['catatan'],
            'is_final'          => true,
        ]);

        // Siapkan deposit carry-over ke Tahun + 1
        $tahunBerikutnya = $tahun + 1;
        PenetapanAK::updateOrCreate(
            [
                'pegawai_id' => $pegawai->id,
                'tahun'      => $tahunBerikutnya,
            ],
            [
                'ak_dasar'          => $pegawai->pangkatGolongan?->ak_dasar ?? 0,
                'ak_pak_pelantikan' => 0,
                'ak_historis'       => 0,
                'ak_lama'           => $kelayakanAkhir['carry_over'],
                'ak_carry_over'     => $kelayakanAkhir['carry_over'],
                'ak_baru'           => 0,
                'ak_booster'        => 0,
                'ak_kumulatif'      => $kelayakanAkhir['carry_over'],
                'status_kelayakan'  => 'BELUM_CUKUP',
                'is_final'          => false,
            ]
        );

        // Kirim notifikasi ke pegawai
        if ($pegawai->user) {
            $tipeNotif = $kelayakanAkhir['status'] === 'BELUM_CUKUP' ? 'INFO' : 'SUCCESS';
            Notifikasi::create([
                'user_id' => $pegawai->user->id,
                'judul'   => "Penetapan Angka Kredit Tahun {$tahun} Selesai - [{$kelayakanAkhir['badge_label']}]",
                'pesan'   => "Penetapan AK Anda tahun {$tahun} telah selesai. Total AK Kumulatif: {$akKumulatif} AK. Status: {$kelayakanAkhir['badge_label']}. {$kelayakanAkhir['catatan']}",
                'tipe'    => $tipeNotif,
            ]);
        }

        $this->auditTrail->log(
            'PENETAPAN_AK',
            'AUTO_FINALISASI',
            "Penetapan kinerja tahunan & finalisasi PAK tahun {$tahun} untuk pegawai {$pegawai->nama_lengkap} (NIP: {$pegawai->nip}) selesai. Status: {$kelayakanAkhir['badge_label']} (Kumulatif: {$akKumulatif} AK, Carry-over: {$kelayakanAkhir['carry_over']} AK)."
        );

        return [
            'evaluasi'   => $evaluasiTw4,
            'penetapan'  => $penetapan->fresh(),
            'kelayakan'  => $kelayakanAkhir,
        ];
    }

    /**
     * Sinkronisasi data ke PenetapanAK saat evaluasi kinerja ditambah/diubah/dihapus (TW1-TW3).
     */
    protected function syncPenetapanAK(string $pegawaiId, int $tahun): void
    {
        $penetapan = PenetapanAK::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->first();

        if (!$penetapan) {
            return;
        }

        $sumAk = (float) EvaluasiKinerja::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->sum('angka_kredit');

        $liveAkBooster = (float) PengajuanPendidikan::where('pegawai_id', $pegawaiId)
            ->where('status', 'DISETUJUI')
            ->where(function ($q) use ($tahun) {
                $q->whereYear('diverifikasi_pada', $tahun)
                  ->orWhereYear('created_at', $tahun);
            })
            ->sum('ak_bonus');

        $akLamaEffective = (float) $penetapan->ak_lama > 0
            ? (float) $penetapan->ak_lama
            : (float) $penetapan->ak_dasar + (float) $penetapan->ak_pak_pelantikan + (float) $penetapan->ak_historis + (float) $penetapan->ak_carry_over;

        $akBaru = round($sumAk, 3);
        $akKumulatif = round($akLamaEffective + $akBaru + $liveAkBooster, 3);

        $pegawai = Pegawai::with(['pangkatGolongan.jenjangJabatan', 'jenjangJabatan'])->find($pegawaiId);
        $targetEvaluasiJenjang = $pegawai ? $this->getTargetEvaluasiJenjang($pegawai) : null;
        $kelayakan = $pegawai
            ? $this->carryOverService->evaluasiKelayakan($pegawai, $akKumulatif, $targetEvaluasiJenjang)
            : ['status' => 'BELUM_CUKUP', 'catatan' => ''];

        $tw4Ada = EvaluasiKinerja::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->where('triwulan', 4)
            ->exists();

        $penetapan->update([
            'ak_baru'          => $akBaru,
            'ak_booster'       => $liveAkBooster,
            'ak_kumulatif'     => $akKumulatif,
            'status_kelayakan' => $tw4Ada ? $kelayakan['status'] : 'BELUM_CUKUP',
            'catatan_kelayakan'=> $tw4Ada ? $kelayakan['catatan'] : null,
            'is_final'         => $tw4Ada,
            'is_locked'        => $tw4Ada ? $penetapan->is_locked : false,
        ]);

        if (!$tw4Ada) {
            PenetapanAK::where('pegawai_id', $pegawaiId)
                ->where('tahun', $tahun + 1)
                ->where('is_final', false)
                ->update([
                    'ak_lama'       => 0,
                    'ak_carry_over' => 0,
                    'ak_kumulatif'  => 0,
                ]);
        }
    }
}
