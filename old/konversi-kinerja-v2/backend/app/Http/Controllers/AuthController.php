<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login user (Bearer token via Sanctum).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak cocok dengan data kami.'],
            ]);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token'   => $token,
            'user'    => $user->load('pegawai.pangkatGolongan.jenjangJabatan', 'pegawai.jenjangJabatan'),
        ]);
    }

    /**
     * Logout user — hapus token aktif.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Dapatkan profil user saat ini.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load('pegawai.pangkatGolongan.jenjangJabatan', 'pegawai.jenjangJabatan'),
        ]);
    }
}
