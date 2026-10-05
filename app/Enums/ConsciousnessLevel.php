<?php

namespace App\Enums;

/**
 * Tingkat kesadaran. Nilai backing = kunci select pada formulir observasi
 * phase1 dan kunci pada EWS_TABLE.Kesadaran (config/ews.php).
 *
 * DPO = "Dalam Pengaruh Obat" (sedasi). Untuk DPO skor EWS tetap 0, dan skor
 * sebenarnya digantikan oleh RASS yang dicatat terpisah pada observasi.
 */
enum ConsciousnessLevel: string
{
    case ALERT = 'Alert';
    case VOICE = 'Voice';
    case PAIN = 'Pain';
    case UNRESPONSIVE = 'Unresponsive';
    case DPO = 'DPO';

    public function label(): string
    {
        return match ($this) {
            self::ALERT => 'Alert (Sadar Penuh)',
            self::VOICE => 'Respon Suara (Voice)',
            self::PAIN => 'Respon Nyeri (Pain)',
            self::UNRESPONSIVE => 'Unresponsive',
            self::DPO => 'DPO (Dalam Pengaruh Obat / Sedasi)',
        };
    }

    /**
     * Skor parameter "Kesadaran" pada British Early Warning Scale.
     * Lihat config('ews.parameters.Kesadaran.values').
     */
    public function ewsScore(): int
    {
        return match ($this) {
            self::ALERT, self::DPO => 0,
            self::VOICE => 1,
            self::PAIN => 2,
            self::UNRESPONSIVE => 3,
        };
    }

    /**
     * DPO memerlukan RASS sebagai pengganti AVPU.
     */
    public function requiresRass(): bool
    {
        return $this === self::DPO;
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
