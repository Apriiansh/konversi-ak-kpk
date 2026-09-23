<?php

namespace Database\Seeders;

use App\Models\MasterPangkatGolongan;
use App\Models\Pegawai;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserPegawaiSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Admin
        User::firstOrCreate(
            ['email' => 'admin@kpk.go.id'],
            [
                'name'     => 'Admin Kepegawaian',
                'password' => Hash::make('password123'),
                'role'     => 'ADMIN',
            ]
        );

        // Cari Golongan IV/a untuk Atasan
        $pangkatAtasan = MasterPangkatGolongan::where('golongan', 'IV/a')->first();
        // Cari Golongan III/a untuk Pegawai Bawahan
        $pangkatBawahan = MasterPangkatGolongan::where('golongan', 'III/a')->first();

        // 2. User & Pegawai: Atasan Penilai
        $userAtasan = User::firstOrCreate(
            ['email' => 'atasan@kpk.go.id'],
            [
                'name'     => 'Bambang Haryanto, M.M',
                'password' => Hash::make('password123'),
                'role'     => 'PEGAWAI',
            ]
        );

        $pegawaiAtasan = Pegawai::firstOrCreate(
            ['nip' => '198005122005011002'],
            [
                'user_id'             => $userAtasan->id,
                'nama_lengkap'        => 'Bambang Haryanto, M.M',
                'pangkat_golongan_id' => $pangkatAtasan->id,
                'pendidikan_terakhir' => 'S2',
            ]
        );

        // 3. User & Pegawai: Pegawai Fungsional
        $userBawahan = User::firstOrCreate(
            ['email' => 'pegawai@kpk.go.id'],
            [
                'name'     => 'Ahmad Fajar, S.Kom',
                'password' => Hash::make('password123'),
                'role'     => 'PEGAWAI',
            ]
        );

        Pegawai::firstOrCreate(
            ['nip' => '199501012022031001'],
            [
                'user_id'             => $userBawahan->id,
                'nama_lengkap'        => 'Ahmad Fajar, S.Kom',
                'atasan_id'           => $pegawaiAtasan->id,
                'pangkat_golongan_id' => $pangkatBawahan->id,
                'pendidikan_terakhir' => 'S1',
            ]
        );
    }
}
