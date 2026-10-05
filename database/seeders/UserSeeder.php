<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Tiga akun petugas, di-port verbatim dari phase1/auth.js (ACCOUNTS).
 *
 * Catatan pemetaan role:
 * - phase1 menyimpan `role` sebagai label tampilan ("Perawat ICU", "Bidan",
 *   "DPJP") yang hanya dipakai di header. Kolom `users.role` di Laravel adalah
 *   App\Enums\UserRole, jadi label tampilan dipetakan ke nilai enum dan
 *   disimpan pada kolom specialty sebagai informasi jabatan dan keahlian klinis.
 *
 * Email dibuat unik dari username karena ada unique index pada users_email_unique;
 * phase1 tidak punya email sama sekali karena auth-nya berbasis localStorage.
 */
class UserSeeder extends Seeder
{
    /**
     * @var list<array{username: string, password: string, name: string, role: UserRole, specialty: string, phone: string}>
     */
    protected array $accounts = [
        [
            'username' => 'perawat',
            'password' => 'perawat123',
            'name' => 'Ns. Tri Handayani',
            'role' => UserRole::PERAWAT,
            'specialty' => 'Perawat ICU',
            'phone' => '081234567801',
        ],
        [
            'username' => 'bidan',
            'password' => 'bidan123',
            'name' => 'Bd. Sari Wulandari',
            'role' => UserRole::BIDAN,
            'specialty' => 'Bidan',
            'phone' => '081234567802',
        ],
        [
            'username' => 'dokter',
            'password' => 'dokter123',
            'name' => 'dr. Rangga Saputra',
            'role' => UserRole::DOKTER,
            'specialty' => 'Konsultan Intensif',
            'phone' => '081234567803',
        ],
    ];

    /**
     * Upsert berdasarkan `username` (ada unique index) sehingga `db:seed`
     * boleh dijalankan berulang tanpa bentrok.
     */
    public function run(): void
    {
        foreach ($this->accounts as $account) {
            User::query()->updateOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'email' => $account['username'].'@rsprotinsulu.local',
                    'password' => Hash::make($account['password']),
                    'role' => $account['role'],
                    'specialty' => $account['specialty'],
                    'phone' => $account['phone'],
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
