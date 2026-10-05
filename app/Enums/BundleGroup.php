<?php

namespace App\Enums;

/**
 * Bundle pencegahan infeksi yang dinilai per observasi.
 * Cocok dengan BUNDLE_GROUPS pada config/hai.php.
 */
enum BundleGroup: string
{
    case VAP = 'vap';
    case CLABSI = 'clabsi';
    case CAUTI = 'cauti';

    public function label(): string
    {
        return match ($this) {
            self::VAP => 'VAP Bundle (Ventilator)',
            self::CLABSI => 'CLABSI Bundle (CVC Jugular)',
            self::CAUTI => 'CAUTI Bundle (Dower Catheter)',
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
