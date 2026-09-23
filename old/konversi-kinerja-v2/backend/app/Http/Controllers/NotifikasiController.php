<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    /**
     * Tampilkan semua notifikasi milik pengguna yang sedang login.
     */
    public function index(Request $request): JsonResponse
    {
        $notifikasi = Notifikasi::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Daftar notifikasi berhasil diambil.',
            'data'    => $notifikasi,
        ]);
    }

    /**
     * Tandai notifikasi tertentu sebagai telah dibaca.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notifikasi = Notifikasi::where('user_id', $request->user()->id)->findOrFail($id);
        $notifikasi->update(['dibaca' => true]);

        return response()->json([
            'message' => 'Notifikasi ditandai telah dibaca.',
            'data'    => $notifikasi,
        ]);
    }

    /**
     * Tandai semua notifikasi pengguna sebagai telah dibaca.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notifikasi::where('user_id', $request->user()->id)
            ->where('dibaca', false)
            ->update(['dibaca' => true]);

        return response()->json([
            'message' => 'Semua notifikasi ditandai telah dibaca.',
        ]);
    }
}
