<?php

namespace App\Http\Controllers;

use App\Models\EvaluasiKinerja;
use App\Models\PengajuanPendidikan;
use App\Models\RiwayatAktivitas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RiwayatAktivitasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $pegawai = $user->pegawai;
        $pegawaiId = $pegawai?->id;
        $limit = min((int) $request->input('limit', 5), 20);

        $aktivitasList = new Collection();

        // 1. Ambil dari RiwayatAktivitas (Audit Trail)
        $auditQuery = RiwayatAktivitas::query()->latest();
        if ($user->role !== 'ADMIN') {
            $auditQuery->where(function ($q) use ($user, $pegawaiId) {
                $q->where('user_id', $user->id);
                if ($pegawaiId) {
                    $q->orWhere('deskripsi', 'like', "%{$pegawaiId}%");
                    if ($user->pegawai?->nama_lengkap) {
                        $q->orWhere('deskripsi', 'like', "%{$user->pegawai->nama_lengkap}%");
                    }
                }
            });
        }
        $auditList = $auditQuery->limit($limit)->get();
        foreach ($auditList as $audit) {
            $aktivitasList->push([
                'id'           => 'audit-' . $audit->id,
                'judul'        => ucwords(strtolower(str_replace('_', ' ', $audit->modul))),
                'keterangan'   => $audit->deskripsi,
                'angka_kredit' => null,
                'badge'        => $audit->aksi,
                'badge_color'  => 'gray',
                'created_at'   => $audit->created_at?->toISOString() ?? now()->toISOString(),
            ]);
        }

        // 2. Ambil dari Evaluasi Kinerja jika ada profil pegawai
        if ($pegawaiId) {
            $evaluasiQuery = EvaluasiKinerja::with('predikat')
                ->where('pegawai_id', $pegawaiId)
                ->latest();

            foreach ($evaluasiQuery->limit($limit)->get() as $ev) {
                $twLabel = $ev->triwulan ? "Triwulan {$ev->triwulan} {$ev->tahun}" : "Periode Bulan {$ev->periode_bulan} {$ev->tahun}";
                $predikatNama = $ev->predikat?->nama ?? 'Selesai Dievaluasi';
                $ak = (float) $ev->angka_kredit;

                $tahunTerkunci = (bool) \App\Models\PenetapanAK::where('pegawai_id', $pegawaiId)
                    ->where('tahun', $ev->tahun)
                    ->value('is_locked');
                $aktivitasList->push([
                    'id'           => 'eval-' . $ev->id,
                    'judul'        => $twLabel,
                    'keterangan'   => "Evaluasi Kinerja Periodik ({$predikatNama})",
                    'angka_kredit' => $ak > 0 ? '+' . number_format($ak, 3, ',', '.') . ' AK' : '0 AK',
                    'badge'        => $tahunTerkunci ? 'Final' : 'Draft',
                    'badge_color'  => $tahunTerkunci ? 'green' : 'amber',
                    'created_at'   => $ev->created_at?->toISOString() ?? now()->toISOString(),
                ]);
            }

            // 3. Ambil dari Pengajuan Pendidikan
            $pengajuanQuery = PengajuanPendidikan::where('pegawai_id', $pegawaiId)->latest();
            foreach ($pengajuanQuery->limit($limit)->get() as $p) {
                $akBonus = (float) $p->ak_bonus;
                $aktivitasList->push([
                    'id'           => 'edu-' . $p->id,
                    'judul'        => "Bonus Pendidikan {$p->jenjang_pendidikan}",
                    'keterangan'   => "{$p->nama_institusi} - {$p->jurusan}",
                    'angka_kredit' => ($p->status === 'DISETUJUI' && $akBonus > 0) ? '+' . number_format($akBonus, 3, ',', '.') . ' AK' : null,
                    'badge'        => $p->status,
                    'badge_color'  => $p->status === 'DISETUJUI' ? 'green' : ($p->status === 'DIAJUKAN' ? 'amber' : 'red'),
                    'created_at'   => $p->created_at?->toISOString() ?? now()->toISOString(),
                ]);
            }
        }

        // Urutkan berdasarkan created_at descending, ambil $limit teratas
        $sorted = $aktivitasList->sortByDesc('created_at')->values()->take($limit);

        return response()->json([
            'message' => 'Riwayat aktivitas terbaru.',
            'data'    => $sorted,
        ]);
    }
}
