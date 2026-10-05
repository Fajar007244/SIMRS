<?php

namespace App\Enums;

/**
 * Cara pembayaran / Guarantee. Nilai backing sama persis dengan seed phase1.
 */
enum PaymentType: string
{
    case BPJS_PBI = 'BPJS PBI';
    case BPJS_NON_PBI = 'BPJS Non PBI';
    case ASURANSI = 'Asuransi';
    case UMUM = 'Umum';

    public function label(): string
    {
        return match ($this) {
            self::BPJS_PBI => 'BPJS PBI',
            self::BPJS_NON_PBI => 'BPJS Non PBI',
            self::ASURANSI => 'Asurance',
            self::UMUM => 'Umum',
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
