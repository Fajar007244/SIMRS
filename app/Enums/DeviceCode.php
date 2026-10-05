<?php

namespace App\Enums;

/**
 * Katalog perangkat invasif. Cocok dengan DEVICE_CATALOG pada config/hai.php.
 * `bundle` kosong berarti perangkat tidak terikat pada bundle tertentu.
 */
enum DeviceCode: string
{
    case ETT = 'ett';
    case CVC = 'cvc';
    case VENT_TUBING = 'ventTubing';
    case NGT = 'ngt';
    case ARTERIAL = 'arterial';
    case DC = 'dc';
    case INFUS = 'infus';
    case LAIN = 'lain';

    public function label(): string
    {
        return match ($this) {
            self::ETT => 'Endotracheal Tube / Tracheostomy Tube',
            self::CVC => 'Central Venous Catheter / CVC (Jugular)',
            self::VENT_TUBING => 'Tubing Ventilator',
            self::NGT => 'Nasogastric Tube / NGT',
            self::ARTERIAL => 'Arteri Line Catheter (Radial Dextra)',
            self::DC => 'Dower Catheter (Foley No. 16)',
            self::INFUS => 'Infus Perifer',
            self::LAIN => 'Tindakan Lain',
        };
    }

    /**
     * Bundle yang terkait dengan perangkat ini ('' bila tidak terikat).
     */
    public function bundleGroup(): string
    {
        return match ($this) {
            self::ETT, self::VENT_TUBING => BundleGroup::VAP->value,
            self::CVC, self::ARTERIAL => BundleGroup::CLABSI->value,
            self::DC => BundleGroup::CAUTI->value,
            default => '',
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
