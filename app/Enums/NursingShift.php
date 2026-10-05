<?php

namespace App\Enums;

/**
 * Shift asuhan keperawatan / kebidanan.
 *
 * CATATAN: phase1 (cppt.html) menawarkan empat shift: Pagi, Siang, Sore, Malam.
 * Seed data app-context.js hanya memakai Pagi / Siang / Malam, jadi keempatnya
 * tetap didukung agar nilai dari UI tidak gagal di-cast.
 */
enum NursingShift: string
{
    case PAGI = 'Pagi';
    case SIANG = 'Siang';
    case SORE = 'Sore';
    case MALAM = 'Malam';

    public function label(): string
    {
        return $this->value;
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
