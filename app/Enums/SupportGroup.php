<?php

namespace App\Enums;

/**
 * Kelompok area penunjang. `abg` punya tabel sendiri (abg_results) karena
 * bentuk datanya blood-gas, bukan key/value generik.
 */
enum SupportGroup: string
{
    case LAB = 'lab';
    case BLOOD = 'blood';
    case MICRO = 'micro';
    case ABG = 'abg';
    case RAD = 'rad';

    public function label(): string
    {
        return match ($this) {
            self::LAB => 'Laboratorium',
            self::BLOOD => 'Darah Lengkap',
            self::MICRO => 'Mikrobiologi',
            self::ABG => 'AGD',
            self::RAD => 'Radiologi',
        };
    }

    /**
     * Kelompok yang disimpan pada tabel `support_results` (key/value generik).
     * AGD dihandled oleh tabel `abg_results`.
     *
     * @return array<int, string>
     */
    public static function resultGroups(): array
    {
        return [self::LAB->value, self::BLOOD->value, self::MICRO->value, self::RAD->value];
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
