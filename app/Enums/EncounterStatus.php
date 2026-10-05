<?php

namespace App\Enums;

/**
 * Status episode perawatan. Nilai backing mengikuti phase1: 'aktif' / 'selesai'.
 */
enum EncounterStatus: string
{
    case AKTIF = 'aktif';
    case SELESAI = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::AKTIF => 'Aktif',
            self::SELESAI => 'Selesai',
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
