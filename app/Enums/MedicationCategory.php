<?php

namespace App\Enums;

/**
 * Kategori obat/cairan pada koreksi pemberian di formulir observasi.
 *
 * URUTAN DI SINI ADALAH KONTRAK UI: phase1 (farmasi.html CATEGORY_ORDER dan
 * fungsi inferMedicationCategory di observasi.html) mengurutkan baris & diagram
 * batang berdasarkan urutan case di bawah ini. Jangan diacak urutannya.
 */
enum MedicationCategory: string
{
    case INOTROPIK_VASOPRESOR = 'Inotropik / Vasopressor';
    case SEDASI_ANALGESIA = 'Sedasi & Analgesia';
    case ANTIBIOTIK = 'Antibiotik';
    case CAIRAN_ELEKTROLIT = 'Cairan & Elektrolit';
    case OBAT_SYSTEMIC = 'Obat Systemic';
    case LAINNYA = 'Lainnya';

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
