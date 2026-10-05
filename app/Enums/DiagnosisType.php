<?php

namespace App\Enums;

/**
 * Jenis diagnosis pada satu episode perawatan.
 */
enum DiagnosisType: string
{
    case UTAMA = 'utama';
    case PENYERTA = 'penyerta';

    public function label(): string
    {
        return match ($this) {
            self::UTAMA => 'Utama',
            self::PENYERTA => 'Penyerta',
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
