<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePengajuanPendidikanRequest;
use App\Http\Requests\VerifikasiPengajuanRequest;
use App\Models\Notifikasi;
use App\Models\PengajuanPendidikan;
use App\Services\AuditTrailService;
use App\Services\BoosterIjazahService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PengajuanPendidikanController extends Controller
{
    protected BoosterIjazahService $boosterService;
    protected AuditTrailService $auditTrail;

    public function __construct(BoosterIjazahService $boosterService, AuditTrailService $auditTrail)
    {
        $this->boosterService = $boosterService;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Tampilkan daftar pengajuan pendidikan.
     * Admin: melihat semua antrean verifikasi.
     * Pegawai: hanya melihat pengajuan miliknya.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PengajuanPendidikan::with([
            'pegawai.pangkatGolongan.jenjangJabatan',
            'verifikator:id,name,email'
        ])->latest();

        if ($user->role !== 'ADMIN') {
            $pegawaiId = $user->pegawai->id ?? null;
            if (!$pegawaiId) {
                return response()->json(['message' => 'Profil pegawai tidak ditemukan.'], 404);
            }
            $query->where('pegawai_id', $pegawaiId);
        }

        $data = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'message' => 'Daftar pengajuan pendidikan berhasil diambil.',
            'data' => $data,
        ]);
    }

    /**
     * Pegawai mengajukan berkas ijazah baru & bukti BKN.
     */
    public function store(StorePengajuanPendidikanRequest $request): JsonResponse
    {
        $user = $request->user();
        $pegawai = $user->pegawai()->first();

        if (!$pegawai) {
            return response()->json(['message' => 'Akun Anda belum terhubung dengan data pegawai.'], 403);
        }

        // Guard: cegah double submit — satu pengajuan DIAJUKAN aktif cukup (no celah inspect)
        $existingPending = PengajuanPendidikan::where('pegawai_id', $pegawai->id)
            ->where('status', 'DIAJUKAN')
            ->exists();
        if ($existingPending) {
            return response()->json([
                'message' => 'Anda masih memiliki pengajuan dengan status Menunggu Verifikasi. Selesaikan verifikasi terlebih dahulu sebelum mengajukan kembali.',
            ], 422);
        }

        // Upload berkas fisik ke storage
        $fileIjazahPath = $request->file('file_ijazah')->store('pengajuan_pendidikan/ijazah', 'public');
        $fileBknPath = $request->file('file_bukti_bkn')->store('pengajuan_pendidikan/bukti_bkn', 'public');

        $pengajuan = PengajuanPendidikan::create([
            'pegawai_id'         => $pegawai->id,
            'jenjang_pendidikan' => $request->jenjang_pendidikan,
            'program_studi'      => $request->program_studi,
            'jurusan'            => $request->jurusan,
            'nama_institusi'     => $request->nama_institusi,
            'tahun_lulus'        => $request->tahun_lulus,
            'file_ijazah'        => $fileIjazahPath,
            'file_bukti_bkn'     => $fileBknPath,
            'status'             => 'DIAJUKAN',
        ]);

        $this->auditTrail->log(
            'BOOSTER_IJAZAH',
            'SUBMIT',
            "Pegawai {$pegawai->nama_lengkap} (NIP: {$pegawai->nip}) mengajukan berkas pendidikan jenjang {$request->jenjang_pendidikan}.",
            null,
            $pengajuan->toArray()
        );

        return response()->json([
            'message' => 'Pengajuan pendidikan dan berkas bukti BKN berhasil diunggah. Menunggu verifikasi admin.',
            'data' => $pengajuan,
        ], 201);
    }

    /**
     * Detail satu pengajuan pendidikan.
     */
    public function show(string $id): JsonResponse
    {
        $pengajuan = PengajuanPendidikan::with([
            'pegawai.pangkatGolongan.jenjangJabatan',
            'verifikator:id,name,email'
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail pengajuan pendidikan.',
            'data' => $pengajuan,
        ]);
    }

    /**
     * Admin melakukan pengecekan kevalidan dokumen (Approval / Rejection).
     */
    public function verifikasi(VerifikasiPengajuanRequest $request, string $id): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'ADMIN') {
            return response()->json(['message' => 'Hanya Admin Kepegawaian yang berhak memverifikasi dokumen.'], 403);
        }

        $pengajuan = PengajuanPendidikan::findOrFail($id);

        if (in_array($pengajuan->status, ['DISETUJUI', 'DITOLAK_ADMIN', 'DITOLAK_SYARAT'])) {
            return response()->json(['message' => 'Pengajuan ini sudah pernah diverifikasi sebelumnya.'], 400);
        }

        $isValid = $request->boolean('is_valid');

        // Jika dokumen dinilai TIDAK VALID oleh admin
        if (!$isValid) {
            $pengajuan->update([
                'status'              => 'DITOLAK_ADMIN',
                'catatan_verifikasi'  => $request->catatan,
                'diverifikasi_oleh'   => $user->id,
                'diverifikasi_pada'   => now(),
            ]);

            // Kirim notifikasi ke pegawai terkait penolakan berkas
            if ($pengajuan->pegawai && $pengajuan->pegawai->user) {
                Notifikasi::create([
                    'user_id' => $pengajuan->pegawai->user->id,
                    'judul'   => 'Dokumen Pengajuan Pendidikan Tidak Valid',
                    'pesan'   => "Pengajuan jenjang {$pengajuan->jenjang_pendidikan} ditolak oleh verifikator. Alasan: {$request->catatan}",
                    'tipe'    => 'DANGER',
                ]);
            }

            $this->auditTrail->log(
                'BOOSTER_IJAZAH',
                'REJECT_ADMIN',
                "Admin {$user->name} menolak berkas pengajuan ID {$id}. Alasan: {$request->catatan}"
            );

            $this->syncBoosterPenetapan($pengajuan->pegawai_id, (int) now()->year);

            return response()->json([
                'message' => 'Dokumen pengajuan telah ditolak.',
                'data'    => $pengajuan,
            ]);
        }

        // Jika dokumen VALID, jalankan mesin pengecekan syarat otomatis & bonus AK
        $hasil = $this->boosterService->prosesPersetujuan($pengajuan, $user);

        return response()->json([
            'message' => $hasil->status === 'DISETUJUI'
                ? 'Dokumen valid & syarat otomatis terpenuhi. Bonus AK 25% berhasil ditetapkan.'
                : 'Dokumen valid, namun pengajuan ditolak sistem karena tidak memenuhi kriteria otomatis.',
            'data'    => $hasil,
        ]);
    }

    /**
     * Hapus pengajuan pendidikan (Pegawai atau Admin).
     * Otomatis menyinkronkan kembali AK Booster & progress bar di PenetapanAK.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $pengajuan = PengajuanPendidikan::findOrFail($id);

        if ($user->role !== 'ADMIN' && $pengajuan->pegawai_id !== $user->pegawai?->id) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk menghapus pengajuan ini.'], 403);
        }

        $pegawaiId = $pengajuan->pegawai_id;
        $tahunPengajuan = $pengajuan->diverifikasi_pada ? (int) $pengajuan->diverifikasi_pada->year : (int) $pengajuan->created_at->year;

        // Hapus file jika ada
        if ($pengajuan->file_ijazah) {
            Storage::disk('public')->delete($pengajuan->file_ijazah);
        }
        if ($pengajuan->file_bukti_bkn) {
            Storage::disk('public')->delete($pengajuan->file_bukti_bkn);
        }

        $pengajuan->delete();

        // Sinkronkan ulang PenetapanAK tahun tersebut & tahun berjalan
        $this->syncBoosterPenetapan($pegawaiId, $tahunPengajuan);
        $this->syncBoosterPenetapan($pegawaiId, (int) now()->year);

        return response()->json(['message' => 'Pengajuan pendidikan berhasil dihapus.']);
    }

    /**
     * Helper sinkronisasi AK Booster di tabel penetapan_ak.
     */
    public function syncBoosterPenetapan(string $pegawaiId, int $tahun): void
    {
        $liveAkBooster = (float) PengajuanPendidikan::where('pegawai_id', $pegawaiId)
            ->where('status', 'DISETUJUI')
            ->where(function ($q) use ($tahun) {
                $q->whereYear('diverifikasi_pada', $tahun)
                  ->orWhereYear('created_at', $tahun);
            })
            ->sum('ak_bonus');

        $penetapan = \App\Models\PenetapanAK::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->first();

        if ($penetapan) {
            $akLamaEffective = (float) $penetapan->ak_lama > 0
                ? (float) $penetapan->ak_lama
                : (float) $penetapan->ak_dasar + (float) $penetapan->ak_pak_pelantikan + (float) $penetapan->ak_historis + (float) $penetapan->ak_carry_over;

            $akKumulatif = round($akLamaEffective + (float) $penetapan->ak_baru + $liveAkBooster, 2);

            $penetapan->update([
                'ak_booster'   => $liveAkBooster,
                'ak_kumulatif' => $akKumulatif,
            ]);
        }
    }
}
