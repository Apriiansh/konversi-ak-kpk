<?php

namespace App\Http\Controllers;

use App\Models\MasterPangkatGolongan;
use App\Models\MasterJenjangJabatan;
use App\Models\Pegawai;
use App\Models\PenetapanAK;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\HitungKonversiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PegawaiController extends Controller
{
    protected AuditTrailService $auditTrail;
    protected HitungKonversiService $hitungKonversi;

    public function __construct(AuditTrailService $auditTrail, HitungKonversiService $hitungKonversi)
    {
        $this->auditTrail = $auditTrail;
        $this->hitungKonversi = $hitungKonversi;
    }

    /**
     * Daftar pegawai lengkap dengan pangkat, jenjang & atasan.
     * Admin: semua. Pegawai/Atasan: hanya data dirinya sendiri.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Pegawai::query()
            ->with([
                'pangkatGolongan.jenjangJabatan',
                'jenjangJabatan',
                'atasan:id,nama_lengkap,nip',
                'user:id,name,email,role',
            ])
            ->latest();

        if ($user->role !== 'ADMIN') {
            $pegawaiId = $user->pegawai->id ?? null;
            if (! $pegawaiId) {
                return response()->json(['message' => 'Profil pegawai tidak ditemukan.'], 404);
            }
            $query->where('id', $pegawaiId);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'ilike', "%{$search}%")
                    ->orWhere('nip', 'ilike', "%{$search}%");
            });
        }

        return response()->json([
            'message' => 'Daftar pegawai.',
            'data' => $query->paginate($request->input('per_page', 15)),
        ]);
    }

    /**
     * Tambah pegawai baru, sekaligus membuat akun user (PEGAWAI) miliknya.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nip'                 => 'required|string|unique:pegawai,nip',
            'nama_lengkap'        => 'required|string|max:255',
            'pangkat_golongan_id' => 'required|uuid|exists:master_pangkat_golongan,id',
            'asal_jabatan'        => 'nullable|in:JABATAN_FUNGSIONAL,PELAKSANA,PENGAWAS,ADMINISTRATOR,PENGANGKATAN_PERTAMA',
            'jenjang_jabatan_id'  => 'nullable|uuid|exists:master_jenjang_jabatan,id',
            'atasan_id'           => 'nullable|uuid|exists:pegawai,id',
            'pendidikan_terakhir' => 'nullable|string|max:100',
            'tmt_jabatan'         => 'nullable|date',
            'masa_kerja_tahun'    => 'nullable|integer|min:0',
            'masa_kerja_bulan'    => 'nullable|integer|min:0|max:11',
            'saldo_historis'      => 'nullable|numeric|min:0',
            'tahun'               => 'nullable|integer|min:2020',
            'email'               => 'required|email|unique:users,email',
            'password'            => 'required|string|min:8',
        ]);

        $pegawai = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name'     => $validated['nama_lengkap'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role'     => 'PEGAWAI',
            ]);

            $pegawai = Pegawai::create([
                'user_id'             => $user->id,
                'nip'                 => $validated['nip'],
                'nama_lengkap'        => $validated['nama_lengkap'],
                'atasan_id'           => $validated['atasan_id'] ?? null,
                'pangkat_golongan_id' => $validated['pangkat_golongan_id'],
                'asal_jabatan'        => $validated['asal_jabatan'] ?? 'PELAKSANA',
                'jenjang_jabatan_id'  => $validated['jenjang_jabatan_id'] ?? null,
                'pendidikan_terakhir' => $validated['pendidikan_terakhir'] ?? null,
                'tmt_jabatan'         => $validated['tmt_jabatan'] ?? null,
            ]);

            // Inisialisasi modal awal PenetapanAK
            $tahunAwal = (int) ($validated['tahun'] ?? (!empty($validated['tmt_jabatan']) ? date('Y', strtotime($validated['tmt_jabatan'])) : date('Y')));
            $pangkat = MasterPangkatGolongan::with('jenjangJabatan')->find($validated['pangkat_golongan_id']);
            $targetJenjang = !empty($validated['jenjang_jabatan_id'])
                ? MasterJenjangJabatan::find($validated['jenjang_jabatan_id'])
                : null;
            $jenjangEfektif = $targetJenjang ?: $pangkat?->jenjangJabatan;
            $koefisienTahunan = (float) ($jenjangEfektif?->koefisien_tahunan ?? 12.5);

            $penyesuaian = $this->hitungKonversi->resolveMismatchPenyesuaian(
                $validated['asal_jabatan'] ?? 'PELAKSANA',
                $pangkat?->golongan,
                $jenjangEfektif
            );

            $akDasar = (float) ($pangkat?->ak_dasar ?? 0);
            $mkTahun = (int) ($validated['masa_kerja_tahun'] ?? 0);
            $mkBulan = (int) ($validated['masa_kerja_bulan'] ?? 0);
            $akPakPelantikan = 0.0;
            if ($penyesuaian['flat']) {
                $akPakPelantikan = 100.00;
            } elseif ($mkTahun > 0 || $mkBulan > 0) {
                $calcPak = $this->hitungKonversi->hitungPakPelantikan($mkTahun, $mkBulan, 1.0, $koefisienTahunan);
                $akPakPelantikan = (float) $calcPak['total_ak_pak'];
            }

            $akHistoris = (float) ($validated['saldo_historis'] ?? 0);
            $akKumulatif = round($akDasar + $akPakPelantikan + $akHistoris, 3);

            PenetapanAK::create([
                'pegawai_id'        => $pegawai->id,
                'tahun'             => $tahunAwal,
                'ak_dasar'          => $akDasar,
                'ak_pak_pelantikan' => $akPakPelantikan,
                'ak_historis'       => $akHistoris,
                'ak_lama'           => 0,
                'ak_baru'           => 0,
                'ak_booster'        => 0,
                'ak_carry_over'     => 0,
                'ak_kumulatif'      => $akKumulatif,
                'status_kelayakan'  => 'BELUM_CUKUP',
                'is_final'          => false,
            ]);

            $this->auditTrail->log(
                'PEGAWAI',
                'CREATE',
                "Menambahkan pegawai {$validated['nama_lengkap']} (NIP: {$validated['nip']}) dengan modal awal {$akKumulatif} AK tahun {$tahunAwal}.",
                null,
                $pegawai->toArray()
            );

            return $pegawai;
        });

        return response()->json([
            'message' => 'Pegawai berhasil ditambahkan.',
            'data' => $pegawai->load('pangkatGolongan.jenjangJabatan', 'jenjangJabatan', 'user:id,name,email,role'),
        ], 201);
    }

    /**
     * Detail satu pegawai.
     */
    public function show(string $id): JsonResponse
    {
        $pegawai = Pegawai::with([
            'pangkatGolongan.jenjangJabatan',
            'jenjangJabatan',
            'atasan:id,nama_lengkap,nip',
            'bawahan:id,nama_lengkap,nip',
            'user:id,name,email,role',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail pegawai.',
            'data' => $pegawai,
        ]);
    }

    /**
     * Update data pegawai (data kepegawaian, bukan akun user).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $pegawai = Pegawai::findOrFail($id);

        $validated = $request->validate([
            'nama_lengkap'        => 'sometimes|string|max:255',
            'pangkat_golongan_id' => 'sometimes|uuid|exists:master_pangkat_golongan,id',
            'asal_jabatan'        => 'nullable|in:JABATAN_FUNGSIONAL,PELAKSANA,PENGAWAS,ADMINISTRATOR,PENGANGKATAN_PERTAMA',
            'jenjang_jabatan_id'  => 'nullable|uuid|exists:master_jenjang_jabatan,id',
            'atasan_id'           => 'nullable|uuid|exists:pegawai,id',
            'pendidikan_terakhir' => 'nullable|string|max:100',
            'tmt_jabatan'         => 'nullable|date',
        ]);

        $sebelumnya = $pegawai->toArray();
        $pegawai->update($validated);

        $this->auditTrail->log(
            'PEGAWAI',
            'UPDATE',
            "Memperbarui data pegawai {$pegawai->nama_lengkap} (NIP: {$pegawai->nip}).",
            $sebelumnya,
            $pegawai->fresh()->toArray()
        );

        return response()->json([
            'message' => 'Data pegawai berhasil diperbarui.',
            'data' => $pegawai->load('pangkatGolongan.jenjangJabatan', 'jenjangJabatan', 'user:id,name,email,role'),
        ]);
    }

    /**
     * Hapus pegawai beserta akun user terkait.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $pegawai = Pegawai::findOrFail($id);

        $this->auditTrail->log(
            'PEGAWAI',
            'DELETE',
            "Menghapus pegawai {$pegawai->nama_lengkap} (NIP: {$pegawai->nip}).",
            $pegawai->toArray()
        );

        $user = $pegawai->user;
        $pegawai->delete();
        $user?->delete();

        return response()->json([
            'message' => 'Pegawai beserta akun berhasil dihapus.',
        ]);
    }
}
