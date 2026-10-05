<?php

namespace App\Enums;

/**
 * Peran pengguna aplikasi. Otorisasi sengaja dibuat sederhana: satu kolom
 * `role` pada tabel `users` + helper `User::hasRole()`. Tidak memakai
 * spatie/laravel-permission.
 */
enum UserRole: string
{
    case PERAWAT = 'perawat';
    case BIDAN = 'bidan';
    case DOKTER = 'dokter';

    public function label(): string
    {
        return match ($this) {
            self::PERAWAT => 'Perawat',
            self::BIDAN => 'Bidan',
            self::DOKTER => 'Dokter',
        };
    }

    /**
     * @return array<string, string> label => value
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->label()] = $case->value;
        }

        return $options;
    }
}
