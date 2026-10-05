<?php

namespace Database\Seeders;

use App\Enums\SupportGroup;
use App\Models\Encounter;
use App\Models\SupportResult;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Hasil penunjang generik: laboratorium, darah, mikrobiologi, dan radiologi.
 *
 * Sumber: buildSeedSupport() pada phase1/app-context.js. Seluruh 50 baris
 * prototype (6 encounter x lab/blood/micro/rad) disalin apa adanya: nilai
 * angka, waktu sampling, dan nama petugas penguji.
 *
 * prototype tidak menyimpan satuan, rentang referensi, maupun flag, dan nilai
 * kultur disimpan sebagai teks. Ketiganya dikembalikan di sini karena tabel
 * support_results mewajibkannya: `value` menyimpan angka sebagai teks (atau
 * teks bebas untuk kultur dan radiologi), `numeric_value` menyimpan padanan
 * angkanya untuk grafik tren penunjang.html, dan flag diturunkan dari batas
 * rentang referensi per item.
 *
 * Baris tambahan (hasil ulang 1x2 hari pada episode yang sama) sengaja
 * ditambahkan agar setiap item punya lebih dari satu titik waktu. Tanpa itu
 * grafik tren SVG pada penunjang.html hanya dapat menggambar satu titik per
 * item, dan hasil-verbal-radiologi tetap apa adanya.
 */
class SupportResultSeeder extends Seeder
{
    /**
     * Satuan, rentang referensi, dan ambang flag per item.
     * `low` / `high` batas normal, `crit_low` / `crit_high` batas kritis.
     *
     * @var array<string, array{unit: string|null, reference: string|null, low: float, high: float, crit_low: float|null, crit_high: float|null}>
     */
    protected array $catalogue = [
        'leukosit' => ['unit' => 'x10^3/uL', 'reference' => '4,0 - 10,5 x10^3/uL', 'low' => 4.0, 'high' => 10.5, 'crit_low' => 2.0, 'crit_high' => 25.0],
        'hgb' => ['unit' => 'g/dL', 'reference' => '12,0 - 16,0 g/dL', 'low' => 12.0, 'high' => 16.0, 'crit_low' => 8.0, 'crit_high' => 20.0],
        'pjk' => ['unit' => 'x10^3/uL', 'reference' => '150 - 400 x10^3/uL', 'low' => 150.0, 'high' => 400.0, 'crit_low' => 50.0, 'crit_high' => 600.0],
        'lpf' => ['unit' => '%', 'reference' => '1 - 8 %', 'low' => 1.0, 'high' => 8.0, 'crit_low' => null, 'crit_high' => 25.0],
        'crp' => ['unit' => 'mg/L', 'reference' => '< 5,0 mg/L', 'low' => 0.0, 'high' => 5.0, 'crit_low' => null, 'crit_high' => 100.0],
        'prokalcitonin' => ['unit' => 'ng/mL', 'reference' => '< 0,05 ng/mL', 'low' => 0.0, 'high' => 0.05, 'crit_low' => null, 'crit_high' => 2.0],
        'kreatinin' => ['unit' => 'mg/dL', 'reference' => '0,7 - 1,3 mg/dL', 'low' => 0.7, 'high' => 1.3, 'crit_low' => null, 'crit_high' => 4.0],
        'bun' => ['unit' => 'mg/dL', 'reference' => '10 - 20 mg/dL', 'low' => 10.0, 'high' => 20.0, 'crit_low' => null, 'crit_high' => 50.0],
        'natrem' => ['unit' => 'mEq/L', 'reference' => '135 - 145 mEq/L', 'low' => 135.0, 'high' => 145.0, 'crit_low' => 125.0, 'crit_high' => 155.0],
        'kalium' => ['unit' => 'mEq/L', 'reference' => '3,5 - 5,1 mEq/L', 'low' => 3.5, 'high' => 5.1, 'crit_low' => 3.0, 'crit_high' => 6.0],
        'glukosa' => ['unit' => 'mg/dL', 'reference' => '70 - 110 mg/dL', 'low' => 70.0, 'high' => 110.0, 'crit_low' => 50.0, 'crit_high' => 300.0],
        'albumin' => ['unit' => 'g/dL', 'reference' => '3,5 - 5,0 g/dL', 'low' => 3.5, 'high' => 5.0, 'crit_low' => 2.0, 'crit_high' => null],
        'hba1c' => ['unit' => '%', 'reference' => '4,0 - 5,6 %', 'low' => 4.0, 'high' => 5.6, 'crit_low' => null, 'crit_high' => 10.0],
        'anion_gap' => ['unit' => 'mEq/L', 'reference' => '8 - 16 mEq/L', 'low' => 8.0, 'high' => 16.0, 'crit_low' => null, 'crit_high' => 20.0],
    ];

    public function run(): void
    {
        foreach ($this->support() as $encounterCode => $rows) {
            $encounter = Encounter::query()->where('encounter_id', $encounterCode)->first();

            if (! $encounter instanceof Encounter) {
                continue;
            }

            foreach ($rows as $row) {
                SupportResult::query()->updateOrCreate(
                    [
                        'encounter_id' => $encounter->getKey(),
                        'group' => SupportGroup::from($row['g']),
                        'result_key' => $row['k'],
                        'resulted_at' => Carbon::parse($row['at']),
                    ],
                    [
                        'value' => (string) $row['v'],
                        'numeric_value' => $row['n'] ?? null,
                        'unit' => $row['u'] ?? null,
                        'flag' => $row['f'] ?? $this->flag($row['k'], $row['n'] ?? null),
                        'reference' => $row['r'] ?? null,
                        'created_by' => $row['by'],
                    ]
                );
            }
        }
    }

    /**
     * Flag otomatis dari batas rentang referensi, atau null bila itemnya
     * bertipe teks (kultur, hasil radiologi) atau tidak dikenal.
     */
    protected function flag(string $resultKey, int|float|null $numeric): ?string
    {
        if ($numeric === null || ! isset($this->catalogue[$resultKey])) {
            return null;
        }

        $entry = $this->catalogue[$resultKey];

        if ($entry['crit_low'] !== null && $numeric < $entry['crit_low']) {
            return 'critical';
        }

        if ($entry['crit_high'] !== null && $numeric > $entry['crit_high']) {
            return 'critical';
        }

        if ($numeric < $entry['low']) {
            return 'low';
        }

        if ($numeric > $entry['high']) {
            return 'high';
        }

        return 'normal';
    }

    /*
    |--------------------------------------------------------------------------
    | Data penunjang
    |--------------------------------------------------------------------------
    |
    | Bentuk baris: g = kelompok, k = kunci item, v = nilai tampilan,
    | n = padanan angka, at = waktu hasil, by = petugas. Field opsional:
    | u (satuan), r (rentang referensi), f (flag manual untuk hasil teks).
    |
    */
    protected function support(): array
    {
        $lab = 'Dra. Sari Wulandari';
        $dokter = 'dr. Rangga Saputra';

        return [
            'enc-159853-icu-20260906' => [
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 18.4, 'n' => 18.4, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 10.2, 'n' => 10.2, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 88, 'n' => 88, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'lpf', 'v' => 2, 'n' => 2, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 128, 'n' => 128, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'prokalcitonin', 'v' => 4.2, 'n' => 4.2, 'at' => '2026-09-06 11:00', 'by' => 'Dr. Rangga Saputra'],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 14.2, 'n' => 14.2, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 10.6, 'n' => 10.6, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 96, 'n' => 96, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'lpf', 'v' => 3, 'n' => 3, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 84, 'n' => 84, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'prokalcitonin', 'v' => 2.1, 'n' => 2.1, 'at' => '2026-09-07 09:30', 'by' => 'Dr. Rangga Saputra'],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 2.4, 'n' => 2.4, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'bun', 'v' => 38, 'n' => 38, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 141, 'n' => 141, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.1, 'n' => 4.1, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 156, 'n' => 156, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'albumin', 'v' => 2.6, 'n' => 2.6, 'at' => '2026-09-06 09:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 2.2, 'n' => 2.2, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'bun', 'v' => 34, 'n' => 34, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 140, 'n' => 140, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.0, 'n' => 4.0, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 148, 'n' => 148, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'albumin', 'v' => 2.7, 'n' => 2.7, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'micro', 'k' => 'kultur_darah', 'v' => 'Pending', 'f' => 'pending', 'at' => '2026-09-06 10:00', 'by' => $dokter],
                ['g' => 'micro', 'k' => 'kultur_darah', 'v' => 'Pending', 'f' => 'pending', 'at' => '2026-09-07 09:00', 'by' => $dokter],
                ['g' => 'micro', 'k' => 'kultur_sputum', 'v' => 'Pending', 'f' => 'pending', 'at' => '2026-09-07 09:00', 'by' => $dokter],
                ['g' => 'rad', 'k' => 'rontgen_toraks', 'v' => 'Bronkopneumonia bilateral, tidak ada pneumotoraks', 'f' => 'selesai', 'at' => '2026-09-06 11:30', 'by' => 'dr. Budi Santoso, Sp.R'],
                ['g' => 'rad', 'k' => 'rontgen_toraks', 'v' => 'Bronkopneumonia bilateral membaik, tidak ada pneumotoraks', 'f' => 'selesai', 'at' => '2026-09-07 08:15', 'by' => 'dr. Budi Santoso, Sp.R'],
            ],
            'enc-162210-hcu-20260910' => [
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 13.2, 'n' => 13.2, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 11.8, 'n' => 11.8, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 240, 'n' => 240, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 42, 'n' => 42, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 9.8, 'n' => 9.8, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 11.5, 'n' => 11.5, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 198, 'n' => 198, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 24, 'n' => 24, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 8.2, 'n' => 8.2, 'at' => '2026-09-12 06:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 172, 'n' => 172, 'at' => '2026-09-12 06:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 16, 'n' => 16, 'at' => '2026-09-12 06:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 0.9, 'n' => 0.9, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 186, 'n' => 186, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 138, 'n' => 138, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.0, 'n' => 4.0, 'at' => '2026-09-10 15:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 0.9, 'n' => 0.9, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 152, 'n' => 152, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 138, 'n' => 138, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.1, 'n' => 4.1, 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 138, 'n' => 138, 'at' => '2026-09-12 06:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 139, 'n' => 139, 'at' => '2026-09-12 06:00', 'by' => $lab],
            ],
            'enc-158774-icu-20260908' => [
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 11.4, 'n' => 11.4, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 13.6, 'n' => 13.6, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 205, 'n' => 205, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 14, 'n' => 14, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 10.2, 'n' => 10.2, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 13.2, 'n' => 13.2, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 190, 'n' => 190, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 12, 'n' => 12, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 9.4, 'n' => 9.4, 'at' => '2026-09-10 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 176, 'n' => 176, 'at' => '2026-09-10 05:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 10, 'n' => 10, 'at' => '2026-09-10 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 1.1, 'n' => 1.1, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 132, 'n' => 132, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 140, 'n' => 140, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.4, 'n' => 4.4, 'at' => '2026-09-08 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 1.1, 'n' => 1.1, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 126, 'n' => 126, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 139, 'n' => 139, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.3, 'n' => 4.3, 'at' => '2026-09-09 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 118, 'n' => 118, 'at' => '2026-09-10 05:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.2, 'n' => 4.2, 'at' => '2026-09-10 05:00', 'by' => $lab],
            ],
            'enc-160045-hcu-20260911' => [
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 16.8, 'n' => 16.8, 'at' => '2026-09-11 20:15', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 12.4, 'n' => 12.4, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 10.6, 'n' => 10.6, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 210, 'n' => 210, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'crp', 'v' => 78, 'n' => 78, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hba1c', 'v' => 9.4, 'n' => 9.4, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 342, 'n' => 342, 'at' => '2026-09-11 20:15', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 1.6, 'n' => 1.6, 'at' => '2026-09-11 20:15', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 130, 'n' => 130, 'at' => '2026-09-11 20:15', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 5.4, 'n' => 5.4, 'at' => '2026-09-11 20:15', 'by' => $lab],
                ['g' => 'blood', 'k' => 'glukosa', 'v' => 218, 'n' => 218, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 1.2, 'n' => 1.2, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 134, 'n' => 134, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.6, 'n' => 4.6, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'anion_gap', 'v' => 12, 'n' => 12, 'at' => '2026-09-12 08:00', 'by' => $lab],
                ['g' => 'micro', 'k' => 'kultur_urine', 'v' => 'Escherichia coli > 10^5 CFU/mL', 'f' => 'positif', 'at' => '2026-09-12 08:00', 'by' => $lab],
            ],
            'enc-157330-icu-20260905' => [
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 7.2, 'n' => 7.2, 'at' => '2026-09-05 11:30', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 7.4, 'n' => 7.4, 'at' => '2026-09-05 11:30', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 310, 'n' => 310, 'at' => '2026-09-05 11:30', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 6.9, 'n' => 6.9, 'at' => '2026-09-06 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 8.9, 'n' => 8.9, 'at' => '2026-09-06 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 268, 'n' => 268, 'at' => '2026-09-06 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 9.6, 'n' => 9.6, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 240, 'n' => 240, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 5.8, 'n' => 5.8, 'at' => '2026-09-05 11:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'bun', 'v' => 62, 'n' => 62, 'at' => '2026-09-05 11:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 139, 'n' => 139, 'at' => '2026-09-05 11:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 5.2, 'n' => 5.2, 'at' => '2026-09-05 11:30', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 5.1, 'n' => 5.1, 'at' => '2026-09-06 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'bun', 'v' => 54, 'n' => 54, 'at' => '2026-09-06 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 138, 'n' => 138, 'at' => '2026-09-06 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.9, 'n' => 4.9, 'at' => '2026-09-06 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 4.8, 'n' => 4.8, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'bun', 'v' => 50, 'n' => 50, 'at' => '2026-09-07 08:00', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.7, 'n' => 4.7, 'at' => '2026-09-07 08:00', 'by' => $lab],
            ],
            'enc-163901-hcu-20260912' => [
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 14.6, 'n' => 14.6, 'at' => '2026-09-12 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 9.8, 'n' => 9.8, 'at' => '2026-09-12 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 175, 'n' => 175, 'at' => '2026-09-12 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'albumin', 'v' => 2.9, 'n' => 2.9, 'at' => '2026-09-12 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 12.1, 'n' => 12.1, 'at' => '2026-09-13 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'hgb', 'v' => 10.2, 'n' => 10.2, 'at' => '2026-09-13 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 196, 'n' => 196, 'at' => '2026-09-13 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'albumin', 'v' => 3.1, 'n' => 3.1, 'at' => '2026-09-13 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'leukosit', 'v' => 10.8, 'n' => 10.8, 'at' => '2026-09-14 07:45', 'by' => $lab],
                ['g' => 'lab', 'k' => 'pjk', 'v' => 178, 'n' => 178, 'at' => '2026-09-14 07:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 0.8, 'n' => 0.8, 'at' => '2026-09-12 07:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 137, 'n' => 137, 'at' => '2026-09-12 07:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.2, 'n' => 4.2, 'at' => '2026-09-12 07:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kreatinin', 'v' => 0.7, 'n' => 0.7, 'at' => '2026-09-13 07:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 136, 'n' => 136, 'at' => '2026-09-13 07:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'kalium', 'v' => 4.0, 'n' => 4.0, 'at' => '2026-09-13 07:45', 'by' => $lab],
                ['g' => 'blood', 'k' => 'natrem', 'v' => 137, 'n' => 137, 'at' => '2026-09-14 07:45', 'by' => $lab],
            ],
        ];
    }
}
