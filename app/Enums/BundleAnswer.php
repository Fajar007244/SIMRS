<?php

namespace App\Enums;

/**
 * Jawaban ya/tidak pada item bundle. Nilai backing lowercase; phase1 menyimpan
 * bentuk kapital 'Ya'/'Tidak' di localStorage, normalisasi ke sini dilakukan
 * di service/controller.
 */
enum BundleAnswer: string
{
    case YA = 'ya';
    case TIDAK = 'tidak';

    public function label(): string
    {
        return match ($this) {
            self::YA => 'Ya',
            self::TIDAK => 'Tidak',
        };
    }

    public function isCompliant(): bool
    {
        return $this === self::YA;
    }

    /**
     * Terima bentuk kapital ('Ya'/'Tidak') maupun lowercase dari payload UI.
     */
    public static function normalize(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            'ya', 'y', 'yes', 'true', '1' => self::YA,
            'tidak', 't', 'no', 'false', '0' => self::TIDAK,
            default => null,
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
