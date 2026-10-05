<?php

namespace App\Enums;

/**
 * Jenis kelamin pasien. Nilai backing mengikuti string persis seperti
 * disimpan pada prototype phase1 (app-context.js dan pasien.html).
 */
enum Sex: string
{
    case LAKI_LAKI = 'Laki-Laki';
    case PEREMPUAN = 'Perempuan';

    public function label(): string
    {
        return match ($this) {
            self::LAKI_LAKI => 'Laki-Laki',
            self::PEREMPUAN => 'Perempuan',
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
