<?php

namespace App\Services\Support;

/**
 * Rentang referensi, katalog item, dan klasifikasi abnormal untuk area
 * penunjang (laboratorium, darah, mikrobiologi, radiologi) serta AGD.
 *
 * PORT CATATAN dari phase1/pen_support.html:
 *  - REF          = array `REF`   (baris 258-275)
 *  - LAB_CATALOG  = array `LAB_CATALOG` (baris 283-307)
 *  - ABG_REF      = array `ABG_REF` (baris 309-316)
 *  - classify()   = fungsi classify() (baris 355-364)
 *
 * Tabel ini TIDAK ada di config/ pada baseline yang sudah dibangun, dan
 * menampilkan config/ atau migration baru akan bertabrakan dengan developer
 * lain yang sedang bekerja paralel. Karena itu konstanta klinisnya diletakkan
 * di dalam layer service.
 *
 * KESEPAKAIAN TENTANG `flag` (dibandingkan dengan phase1):
 * phase1 memakai empat level berbeda: criticalLow, criticalHigh, high, low.
 * Di sini criticalLow dan criticalHigh digabung menjadi `critical`, karena:
 *  1. Styling kedua level itu di phase1 sama persis (LEVEL_STYLE), jadi tidak
 *     ada informasi visual yang hilang.
 *  2. SupportResult::scopeAbnormal() sudah mengharapkan nilai 'critical'.
 * Arah (rendah/tinggi) tetap terbaca lewat `flagLabel`.
 *
 * Label di phase1 untuk Kritis/Rendah/Tinggi ter-corruption (mojibake, bukan
 * bullet unicode), jadi di sini memakai teks Indonesia yang bersih.
 */
final class ReferenceRange
{
    public const LEVEL_CRITICAL = 'critical';

    public const LEVEL_HIGH = 'high';

    public const LEVEL_LOW = 'low';

    public const LEVEL_NORMAL = 'normal';

    public const LEVEL_NONE = 'none';

    /**
     * LEVEL yang dianggap abnormal, sinkron dengan
     * App\Models\SupportResult::scopeAbnormal().
     */
    public const ABNORMAL_LEVELS = [self::LEVEL_CRITICAL, self::LEVEL_HIGH, self::LEVEL_LOW];

    /**
     * REF phase1: low/high, unit, jumlah desimal, dan batas kritis.
     * Nilai low = high = 0 berarti "tanpa rentang numerik" (mis. elektrolit).
     *
     * @return array<string, array<string, float|int>>
     */
    public static function lab(): array
    {
        return [
            'leukosit' => ['low' => 4.0, 'high' => 11.0, 'unit' => '10^3/uL', 'decimals' => 1, 'criticalLow' => 1.0, 'criticalHigh' => 30.0],
            'hgb' => ['low' => 8.0, 'high' => 16.0, 'unit' => 'g/dL', 'decimals' => 1, 'criticalLow' => 6.0, 'criticalHigh' => 20.0],
            'pjk' => ['low' => 150, 'high' => 450, 'unit' => '10^3/uL', 'decimals' => 0, 'criticalLow' => 50, 'criticalHigh' => 1000],
            'hct' => ['low' => 0, 'high' => 0, 'unit' => '%', 'decimals' => 0],
            'lpf' => ['low' => 0, 'high' => 5.0, 'unit' => '%', 'decimals' => 0, 'criticalHigh' => 25],
            'kreatinin' => ['low' => 0.6, 'high' => 1.3, 'unit' => 'mg/dL', 'decimals' => 1, 'criticalHigh' => 3.0],
            'bun' => ['low' => 7, 'high' => 20, 'unit' => 'mg/dL', 'decimals' => 0, 'criticalHigh' => 50],
            'natrem' => ['low' => 135, 'high' => 145, 'unit' => 'mmol/L', 'decimals' => 0, 'criticalLow' => 120, 'criticalHigh' => 160],
            'kalium' => ['low' => 3.5, 'high' => 5.1, 'unit' => 'mmol/L', 'decimals' => 1, 'criticalLow' => 2.5, 'criticalHigh' => 6.5],
            'glukosa' => ['low' => 70, 'high' => 140, 'unit' => 'mg/dL', 'decimals' => 0, 'criticalLow' => 50, 'criticalHigh' => 400],
            'albumin' => ['low' => 3.5, 'high' => 5.0, 'unit' => 'g/dL', 'decimals' => 1, 'criticalLow' => 2.0],
            'crp' => ['low' => 0, 'high' => 5.0, 'unit' => 'mg/L', 'decimals' => 0, 'criticalHigh' => 100],
            'prokalcitonin' => ['low' => 0, 'high' => 0.5, 'unit' => 'ng/mL', 'decimals' => 2, 'criticalHigh' => 2],
            'laktat' => ['low' => 0.5, 'high' => 2.0, 'unit' => 'mmol/L', 'decimals' => 1, 'criticalHigh' => 4],
            'bilirubin' => ['low' => 0, 'high' => 1.2, 'unit' => 'mg/dL', 'decimals' => 1, 'criticalHigh' => 5],
            'elektrolit' => ['low' => 0, 'high' => 0, 'unit' => '-', 'decimals' => 0],
        ];
    }

    /**
     * LAB_CATALOG phase1: nama tampilan + panel per kelompok.
     * urutan `key` di dalam tiap kelompok ADALAH urutan yang dipakai prototype
     * untuk mengurutkan baris tabel penunjang, jadi harus dipertahankan.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public static function catalog(): array
    {
        return [
            'lab' => [
                ['key' => 'leukosit', 'name' => 'Leukosit', 'panel' => 'Hematologi'],
                ['key' => 'hgb', 'name' => 'Hemoglobin', 'panel' => 'Hematologi'],
                ['key' => 'pjk', 'name' => 'Platelet', 'panel' => 'Hematologi'],
                ['key' => 'lpf', 'name' => 'Lembuk LPF', 'panel' => 'Hematologi'],
                ['key' => 'crp', 'name' => 'CRP', 'panel' => 'Inflamasi'],
                ['key' => 'prokalcitonin', 'name' => 'Prokalcitonin', 'panel' => 'Inflamasi'],
            ],
            'blood' => [
                ['key' => 'laktat', 'name' => 'Laktat', 'panel' => 'Gas Darah'],
                ['key' => 'kreatinin', 'name' => 'Kreatinin', 'panel' => 'Kimia'],
                ['key' => 'bun', 'name' => 'BUN', 'panel' => 'Kimia'],
                ['key' => 'natrem', 'name' => 'Na', 'panel' => 'Elektrolit'],
                ['key' => 'kalium', 'name' => 'K', 'panel' => 'Elektrolit'],
                ['key' => 'glukosa', 'name' => 'Gula Darah', 'panel' => 'Kimia'],
                ['key' => 'albumin', 'name' => 'Albumin', 'panel' => 'Kimia'],
                ['key' => 'bilirubin', 'name' => 'Bilirubin', 'panel' => 'Fungsi Hati'],
            ],
            'micro' => [
                ['key' => 'kultur_darah', 'name' => 'Kultur Darah', 'panel' => 'Mikrobiologi'],
                ['key' => 'kultur_sputum', 'name' => 'Kultur Sputum', 'panel' => 'Mikrobiologi'],
                ['key' => 'kultur_urine', 'name' => 'Kultur Urine', 'panel' => 'Mikrobiologi'],
            ],
        ];
    }

    /**
     * ABG_REF phase1. Kunci memakai nama kolom di tabel abg_results.
     *
     * @return array<string, array<string, float|int>>
     */
    public static function abg(): array
    {
        return [
            'ph' => ['low' => 7.35, 'high' => 7.45, 'criticalLow' => 7.20, 'criticalHigh' => 7.60],
            'pco2' => ['low' => 35, 'high' => 45, 'criticalLow' => 25, 'criticalHigh' => 60],
            'po2' => ['low' => 80, 'high' => 100, 'criticalLow' => 60, 'criticalHigh' => 400],
            'hco3' => ['low' => 22, 'high' => 26, 'criticalLow' => 15, 'criticalHigh' => 35],
            'be' => ['low' => -2, 'high' => 2, 'criticalLow' => -15, 'criticalHigh' => 15],
            'sao2' => ['low' => 95, 'high' => 100, 'criticalLow' => 90, 'criticalHigh' => 100],
        ];
    }

    /**
     * Urutan default grafik tren, dari phase1 renderTrend().
     *
     * @return array<int, array<string, string>>
     */
    public static function trendTargets(): array
    {
        return [
            ['key' => 'leukosit', 'name' => 'Leukosit', 'unit' => '10^3/uL', 'color' => '#dc2626'],
            ['key' => 'hgb', 'name' => 'Hemoglobin', 'unit' => 'g/dL', 'color' => '#7c3aed'],
            ['key' => 'crp', 'name' => 'CRP', 'unit' => 'mg/L', 'color' => '#ea580c'],
            ['key' => 'kreatinin', 'name' => 'Kreatinin', 'unit' => 'mg/dL', 'color' => '#0891b2'],
            ['key' => 'laktat', 'name' => 'Laktat', 'unit' => 'mmol/L', 'color' => '#16a34a'],
        ];
    }

    /**
     * Item tren bawaan untuk tiap kelompok hasil, dipakai getTrend() ketika
     * pemanggil tidak menentukan kunci secara eksplisit.
     *
     * @return array<string, string|null>
     */
    public static function defaultTrendKey(string $group): ?string
    {
        foreach (self::trendTargets() as $target) {
            if (self::meta($target['key'])['group'] === $group) {
                return $target['key'];
            }
        }

        return null;
    }

    /**
     * Rentang referensi untuk satu kunci hasil.
     *
     * @return array<string, float|int>|null
     */
    public static function reference(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        $references = self::lab();

        return $references[$key] ?? null;
    }

    /**
     * Metadata item hasil: key, nama tampilan, panel, dan kelompok asal.
     * Kunci yang tidak ada di katalog (mis. hasil kultur tambahan) tetap
     * dikembalikan dengan panel '-'.
     *
     * @return array{key: string, name: string, panel: string, group: string}
     */
    public static function meta(?string $key): array
    {
        $key = (string) $key;

        foreach (self::catalog() as $group => $items) {
            foreach ($items as $item) {
                if ($item['key'] === $key) {
                    return [
                        'key' => $item['key'],
                        'name' => $item['name'],
                        'panel' => $item['panel'],
                        'group' => $group,
                    ];
                }
            }
        }

        return ['key' => $key, 'name' => $key !== '' ? $key : 'Lainnya', 'panel' => '-', 'group' => ''];
    }

    /**
     * Posisi kunci di dalam urutan katalog kelompok, untuk pengurutan baris.
     * Kunci yang tidak ada di katalog mendapat 999 (muncul paling akhir).
     */
    public static function catalogPosition(string $group, string $key): int
    {
        foreach ((self::catalog()[$group] ?? []) as $index => $item) {
            if ($item['key'] === $key) {
                return $index;
            }
        }

        return 999;
    }

    /**
     * Port persis classify() phase1, dengan criticalLow/criticalHigh digabung.
     *
     * @param  array<string, float|int>|null  $reference
     * @return array{level: string, label: string}
     */
    public static function classify(mixed $value, ?array $reference): array
    {
        $number = ClinicalFormat::numeric($value);

        if ($number === null) {
            return ['level' => self::LEVEL_NONE, 'label' => ClinicalFormat::EMPTY];
        }

        if ($reference === null || ((float) $reference['low'] === 0.0 && (float) $reference['high'] === 0.0)) {
            return ['level' => self::LEVEL_NONE, 'label' => 'Normal'];
        }

        $low = (float) $reference['low'];
        $high = (float) $reference['high'];

        if (array_key_exists('criticalLow', $reference) && $number <= (float) $reference['criticalLow']) {
            return ['level' => self::LEVEL_CRITICAL, 'label' => 'Kritis'];
        }

        if (array_key_exists('criticalHigh', $reference) && $number >= (float) $reference['criticalHigh']) {
            return ['level' => self::LEVEL_CRITICAL, 'label' => 'Kritis'];
        }

        if ($number < $low) {
            return ['level' => self::LEVEL_LOW, 'label' => 'Rendah'];
        }

        if ($number > $high) {
            return ['level' => self::LEVEL_HIGH, 'label' => 'Tinggi'];
        }

        return ['level' => self::LEVEL_NORMAL, 'label' => 'Normal'];
    }

    /**
     * Ringkasan abnormal untuk AGD satu baris, untuk kolom "Analisis".
     * Kunci = nama kolom, level = flag. Diurutkan sesuai urutan pemeriksaan
     * pada tabel AGD supaya tampilan stabil.
     *
     * @return array<string, string>
     */
    public static function abgFlags(array $values): array
    {
        $flags = [];

        foreach (self::abg() as $key => $reference) {
            $number = ClinicalFormat::numeric($values[$key] ?? null);

            if ($number === null) {
                continue;
            }

            $level = self::classify($number, $reference)['level'];

            if ($level !== self::LEVEL_NORMAL && $level !== self::LEVEL_NONE) {
                $flags[$key] = $level;
            }
        }

        return $flags;
    }

    /**
     * Teks rentang referensi siap tampil, mis. "4 - 11 10^3/uL".
     * Mengembalikan '-' bila item tidak punya rentang numerik.
     */
    public static function referenceLabel(?string $key): string
    {
        $reference = self::reference($key);

        if ($reference === null) {
            return ClinicalFormat::EMPTY;
        }

        if ((float) $reference['low'] === 0.0 && (float) $reference['high'] === 0.0) {
            return ClinicalFormat::EMPTY;
        }

        return $reference['low'].' - '.$reference['high'].' '.$reference['unit'];
    }

    public static function unit(?string $key): ?string
    {
        $reference = self::reference($key);

        return $reference === null ? null : (string) $reference['unit'];
    }

    public static function decimals(?string $key): int
    {
        $reference = self::reference($key);

        return $reference === null ? 0 : (int) ($reference['decimals'] ?? 0);
    }
}
