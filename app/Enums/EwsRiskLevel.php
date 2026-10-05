<?php

namespace App\Enums;

/**
 * Tingkat eskalasi risiko berdasarkan total skor EWS.
 * Dipasangkan dengan config('ews.escalation') — harus selalu sinkron.
 */
enum EwsRiskLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case EMERGENCY = 'emergency';

    /**
     * Label panjang yang dipakai banner profil (profil.html, riskLabel).
     */
    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low · stabil',
            self::MEDIUM => 'Sedang · observasi rutin',
            self::HIGH => 'Tinggi · perlu-awasi',
            self::EMERGENCY => 'Emergensi ·intervensi segera',
        };
    }

    /**
     * Label pendek pada badge EWS (app-context.js ewsKategori).
     */
    public function badgeLabel(): string
    {
        return match ($this) {
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
            self::EMERGENCY => 'Emergency',
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
